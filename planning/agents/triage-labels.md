# Triage States

- `needs-triage`: not yet classified.
- `needs-info`: blocked on a decision or missing evidence.
- `ready-for-agent`: decision-complete and executable when dependencies are done.
- `ready-for-human`: requires human judgment or an external action.
- `in-progress`: actively being changed.
- `done`: acceptance criteria and verification are complete.
- `wontfix`: intentionally closed without implementation.

Do not store `blocked`; derive it from unfinished `blocked_by` edges.
