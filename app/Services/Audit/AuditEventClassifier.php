<?php

namespace App\Services\Audit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class AuditEventClassifier
{
    public const CATEGORY_CRITICAL = 'critical';
    public const CATEGORY_IMPORTANT = 'important';
    public const CATEGORY_NOISE = 'noise';

    /** @var array<string, bool>|null */
    private static ?array $auditColumns = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function classify(array $attributes): string
    {
        $event = $this->normalizeString($attributes['event'] ?? null);
        $action = $this->normalizeString($attributes['action'] ?? null);
        $entity = $this->normalizeString($attributes['entity'] ?? null);
        $description = $this->normalizeString($attributes['description'] ?? null);
        $reason = $this->normalizeString($attributes['reason'] ?? null);
        $haystack = trim(implode(' ', array_filter([$event, $action, $entity, $description, $reason])));

        foreach ([self::CATEGORY_CRITICAL, self::CATEGORY_IMPORTANT, self::CATEGORY_NOISE] as $category) {
            $events = $this->eventsFor($category);
            if ($event !== '' && in_array($event, $events, true)) {
                return $category;
            }
        }

        foreach ([self::CATEGORY_CRITICAL, self::CATEGORY_IMPORTANT, self::CATEGORY_NOISE] as $category) {
            foreach ($this->patternsFor($category) as $pattern) {
                if ($pattern !== '' && str_contains($haystack, $pattern)) {
                    return $category;
                }
            }
        }

        return self::CATEGORY_IMPORTANT;
    }

    public function applyCategoryConstraint(Builder $query, string $category): void
    {
        $this->applyMatchers(
            $query,
            $this->eventsFor($category),
            $this->patternsFor($category)
        );
    }

    public function applySelectorConstraint(Builder $query, string $selector): void
    {
        $selectors = (array) config('audit.classification.selectors', []);
        $payload = (array) ($selectors[$selector] ?? []);

        $this->applyMatchers(
            $query,
            collect(Arr::wrap($payload['events'] ?? []))->map(fn ($value) => $this->normalizeString($value))->filter()->values()->all(),
            collect(Arr::wrap($payload['patterns'] ?? []))->map(fn ($value) => $this->normalizeString($value))->filter()->values()->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function knownCategories(): array
    {
        return [
            self::CATEGORY_CRITICAL,
            self::CATEGORY_IMPORTANT,
            self::CATEGORY_NOISE,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eventsFor(string $category): array
    {
        return collect(Arr::wrap(config("audit.classification.{$category}.events", [])))
            ->map(fn ($value) => $this->normalizeString($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function patternsFor(string $category): array
    {
        return collect(Arr::wrap(config("audit.classification.{$category}.patterns", [])))
            ->map(fn ($value) => $this->normalizeString($value))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeString(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * @param  array<int, string>  $events
     * @param  array<int, string>  $patterns
     */
    private function applyMatchers(Builder $query, array $events, array $patterns): void
    {
        if ($events === [] && $patterns === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $outer) use ($events, $patterns): void {
            if ($events !== [] && $this->hasAuditColumn('event')) {
                $placeholders = implode(', ', array_fill(0, count($events), '?'));
                $outer->orWhereRaw('LOWER(event) IN ('.$placeholders.')', $events);
            }

            foreach ($patterns as $pattern) {
                $like = '%'.$pattern.'%';
                $columns = $this->textColumns();
                if ($columns === []) {
                    continue;
                }

                $outer->orWhere(function (Builder $inner) use ($columns, $like): void {
                    foreach ($columns as $index => $column) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $inner->{$method}("LOWER(COALESCE({$column}, '')) LIKE ?", [$like]);
                    }
                });
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function availableAuditColumns(): array
    {
        if (self::$auditColumns !== null) {
            return array_keys(array_filter(self::$auditColumns));
        }

        try {
            self::$auditColumns = array_fill_keys(Schema::getColumnListing('audit_logs'), true);
        } catch (\Throwable $exception) {
            self::$auditColumns = [];
        }

        return array_keys(self::$auditColumns);
    }

    private function hasAuditColumn(string $column): bool
    {
        $columns = array_fill_keys($this->availableAuditColumns(), true);

        return isset($columns[$column]);
    }

    /**
     * @return array<int, string>
     */
    private function textColumns(): array
    {
        return array_values(array_filter([
            $this->hasAuditColumn('event') ? 'event' : null,
            $this->hasAuditColumn('action') ? 'action' : null,
            $this->hasAuditColumn('entity') ? 'entity' : null,
            $this->hasAuditColumn('description') ? 'description' : null,
            $this->hasAuditColumn('reason') ? 'reason' : null,
        ]));
    }
}
