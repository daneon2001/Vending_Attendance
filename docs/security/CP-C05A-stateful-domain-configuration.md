# CP-C05A: explicit Sanctum stateful configuration

Sanctum interprets each stateful entry as a pattern. Synthetic characterization
confirmed that wildcard entries classify unrelated origins as stateful. This is
unsafe configuration, not evidence of an authentication bypass.

## Decision and integration

Reject wildcard configuration in every environment. Validate the effective array
without modifying it. Accept explicit ASCII hostnames, IPv4 and ports 1-65535;
preserve the explicit IPv6 literal format already present in repository defaults.
Whitespace surrounding entries, empty entries/arrays and duplicate explicit hosts
retain their existing meaning. URLs, paths, userinfo, malformed hosts/ports,
non-string entries and raw comma-separated strings are not valid effective arrays.
The dynamic current-request-host placeholder is not used by this project and is
not accepted. Domain matching remains entirely Sanctum's responsibility.

Use `ValidateStatefulDomains` as the first global HTTP middleware. Register it
through `AppServiceProvider::callAfterResolving` for the HTTP kernel, including
the already-resolved kernel on HTTP-first bootstrap. Invalid configuration
returns HTTP 503, a generic JSON message and no-store; no domain list is logged.
It blocks the entire HTTP request, including preflight, before CORS/Sanctum.

An exception directly in provider boot was rejected: both HTTP and console
kernels boot providers. Artisan diagnostics, package discovery (including the
Composer post-autoload hook), and fresh application boots for config:cache and
route:cache would otherwise become unavailable. A beta-only boundary would leave
other environments unprotected. Editing the pending bootstrap/app.php would mix
this patch with backend-beta changes. The provider only installs middleware;
it does not reject CLI configuration.

Consequently about, route:list and cache recovery remain available. Cache command
success does NOT certify safe stateful configuration: HTTP validates the loaded
effective values on every request, also when they came from config cache. After
repairing the private environment configuration, refresh its config cache using
the applicable operational process. No actual environment/cache is changed here.

## Evidence and limits

Before the fix, HTTP regression expectations failed in local/testing/beta/production:
200 instead of 503 (4 failures). Explicit-host cases and characterization passed.
Unit tests cover wildcard patterns, mixed lists, explicit hosts/ports, malformed
entries, whitespace, empty values and duplicates. Feature tests cover all four
environments, generic errors, unchanged configuration, existing Sanctum matching,
HTTP-first boot and console diagnostics with a synthetic wildcard.

Cached-value tests use Laravel's real configuration loader with a temporary file
containing only synthetic app/Sanctum settings, then exercise the protected HTTP
pipeline. This miniature loader has no providers or database bindings; it does not
bypass the repository's prohibition on booting its test application from cached
configuration. A separate temporary console fixture runs the framework's
config:cache/config:clear commands with a synthetic fresh-configuration source.
It proves file lifecycle, not full deployment/cache generation of this application.
No operational caches are loaded, removed or rewritten, and no network is used.

No changes to authentication, permissions, CORS configuration, OTP, mail recovery,
readiness, mobile, LAN, or the pending config/sanctum.php diff. Unsupported or
malformed historical stateful entries now cause HTTP 503 deliberately; operators
must correct them rather than rely on silently filtered values.
