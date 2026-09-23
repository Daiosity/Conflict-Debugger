# Investigation Precision

Version 1.3.0 separates an observation from accepted proof before scoring or
rendering a finding. It favors a small, coherent case over a large collection of
unrelated clues.

## Evidence Pipeline

1. Collect registrations and bounded runtime observations.
2. Normalize each observation through `EvidenceAssessment`.
3. Select one context, resource and request through `EvidenceScope`.
4. Score independent signals, not variations of the same message.
5. Apply `FindingPolicy` caps and render the same assessed evidence.

Direct mutation proof requires a concrete resource and execution surface, a known
request context, a request ID, a server-side source, a distinct actor/resource
owner, the captured mutating callback, and an observed mutation. Contaminated
observations cannot satisfy this contract. Confirmation additionally requires an
explicit failure linked to that same trace. Mere presence of both plugins or a
caller-provided tier is insufficient.

The built-in asset tracer observes changes between lifecycle/priority snapshots.
It does not intercept every WordPress mutation function. Its actor candidates
remain partial attribution, even if only one other plugin was seen at a priority.
The original resource owner can mutate its own resource, nested hooks can run,
and a snapshot boundary is not a function-call trace. Older records claiming direct
attribution without a captured mutator are downgraded when rescanned.

## REST Analysis

`RestRouteInspector` compares handlers on the exact registered route and intersecting
HTTP methods. GET and POST on the same route may coexist. GET also participates in
HEAD dispatch through WordPress's per-handler fallback. Registration order is
reported, but shared methods do not prove incompatible semantics or a failed
response. The inspector never invokes endpoint or permission callbacks.

Reference: [WordPress REST dispatch implementation](https://developer.wordpress.org/reference/classes/wp_rest_server/match_request_to_handler/).

Validation guidance names the route and methods and asks for a staging comparison
with the same authentication state. Write requests should not be replayed on
production as part of diagnosis.

## Attribution Boundaries

- A known actor changing another owner's resource implicates that pair only, not
  every plugin named in the trace.
- A plugin changing its own resource is not a pairwise conflict.
- Ordinary PHP errors are not reinterpreted as asset lifecycle mutations.
- Different resources or request IDs cannot contribute proof to the same selected
  case. Generic background noise stays low-value context.
- A rejected proof claim retains its observation and an explanation in Evidence
  Review. The strong-proof count reflects accepted evidence only.

## Examples

| Observation | Result |
| --- | --- |
| Alpha handles GET; Beta handles POST on the same REST route | No REST method collision |
| Alpha and Beta both handle GET on the same route | Shared surface; inspect dispatch order, not confirmed breakage |
| A style disappears after a priority boundary | Potential interference; actor remains unproven |
| Alpha mutates Beta's handle while Gamma is mentioned in the trace | Only Alpha/Beta receive attributed mutation evidence |
| Direct captured mutation without a failure | Eligible for probable conflict, never confirmed from the mutation alone |
| Mutation on request A and a failure on request B | Not combined into confirmed same-request breakage |

## Remaining Work

Direct call-site capture and controlled request replay remain separate engineering
work. This release does not introduce unsafe callback wrapping, automatic plugin
deactivation, remote AI processing or production request replay. Full multisite
and broad third-party compatibility testing remain necessary before claiming
coverage of every deployment.
