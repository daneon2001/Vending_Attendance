import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process'
import { mkdtemp, rm } from 'node:fs/promises'
import { join } from 'node:path'
import { tmpdir } from 'node:os'
import { createInterface } from 'node:readline'
import type { SupportDatabase } from '@/support/SqliteSupportStore'
import type { SupportContext, TicketInput, ServerTicket } from '@/support/types'

export const DEVICE = '10000000-0000-4000-8000-000000000001'
export const OTHER_DEVICE = '10000000-0000-4000-8000-000000000002'
export const MACHINE = '20000000-0000-4000-8000-000000000001'
export const TICKET = '30000000-0000-4000-8000-000000000001'
export const EVIDENCE = '40000000-0000-4000-8000-000000000001'
export const context: SupportContext = {
  device: { uuid: DEVICE, name: 'Test terminal' }, machine: { uuid: MACHINE, name: 'Test machine', code: 'TEST' },
  categories: [{ value: 'APP', label: 'Aplicación' }],
  evidence_policy: { max_size_bytes: 1_000_000, max_count: 3, allowed_mimes: ['image/jpeg', 'image/png', 'image/webp'] },
  geofence: null, server_time: '2026-09-07T12:00:00Z',
}
export const input: TicketInput = { category: 'APP', title: 'Pantalla detenida', description: 'Reporte sintético de prueba.', reported_at: '2026-09-07T12:00:00Z' }
export const serverTicket: ServerTicket = { uuid: TICKET, folio: 'INC-2026-000001', status: 'OPEN', ...input }

// The repository already requires PHP/PDO SQLite. This bridge exercises actual SQLite
// transactions and disk reopen without installing another JS database dependency.
const PHP = String.raw`
$db = new PDO('sqlite:'.$argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
while (($line = fgets(STDIN)) !== false) {
  $request = json_decode($line, true);
  try {
    $result = [];
    switch ($request['method']) {
      case 'begin': $db->beginTransaction(); break;
      case 'commit': $db->commit(); break;
      case 'rollback': $db->rollBack(); break;
      case 'execute':
        if ($request['transaction']) $db->beginTransaction();
        try { $db->exec($request['sql']); if ($request['transaction']) $db->commit(); }
        catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        break;
      default:
        $stmt = $db->prepare($request['sql']);
        foreach ($request['values'] as $key=>$value) $stmt->bindValue($key+1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->execute();
        if ($request['method'] === 'query') $result = ['values'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    echo json_encode(['id'=>$request['id'],'result'=>$result])."\n";
  } catch (Throwable $error) { echo json_encode(['id'=>$request['id'],'error'=>$error->getMessage()])."\n"; }
  flush();
}
`

class PhpSqlite implements SupportDatabase {
  private process: ChildProcessWithoutNullStreams
  private sequence = 0
  private requests = new Map<number, { resolve: (value: any) => void; reject: (reason: Error) => void }>()
  constructor(path: string) {
    this.process = spawn('php', ['-r', PHP, path], { stdio: 'pipe', windowsHide: true })
    createInterface({ input: this.process.stdout }).on('line', line => {
      const result = JSON.parse(line)
      const pending = this.requests.get(result.id)
      this.requests.delete(result.id)
      if (result.error) pending?.reject(new Error(result.error)); else pending?.resolve(result.result)
    })
    this.process.on('error', error => { for (const pending of this.requests.values()) pending.reject(error) })
    this.process.on('exit', code => { if (code) for (const pending of this.requests.values()) pending.reject(new Error('PHP SQLite adapter exited.')) })
  }
  private call(method: string, sql = '', values: unknown[] = [], transaction = false): Promise<any> {
    return new Promise((resolve, reject) => {
      const id = ++this.sequence
      this.requests.set(id, { resolve, reject })
      this.process.stdin.write(JSON.stringify({ id, method, sql, values, transaction }) + '\n')
    })
  }
  query(sql: string, values: unknown[] = []) { return this.call('query', sql, values) }
  run(sql: string, values: unknown[] = []) { return this.call('run', sql, values) }
  execute(sql: string, transaction = false) { return this.call('execute', sql, [], transaction) }
  beginTransaction() { return this.call('begin') }
  commitTransaction() { return this.call('commit') }
  rollbackTransaction() { return this.call('rollback') }
  async close(): Promise<void> {
    if (this.process.exitCode !== null) return
    await new Promise<void>(resolve => { this.process.once('exit', () => resolve()); this.process.stdin.end() })
  }
}

export async function sqliteFixture() {
  const directory = await mkdtemp(join(tmpdir(), 'vending-support-test-'))
  const path = join(directory, 'support.sqlite')
  const connections: PhpSqlite[] = []
  return {
    async open() { const db = new PhpSqlite(path); connections.push(db); return db },
    async closeConnections() { await Promise.all(connections.map(db => db.close())) },
    async dispose() { await Promise.all(connections.map(db => db.close())); await rm(directory, { recursive: true, force: true }) },
  }
}
