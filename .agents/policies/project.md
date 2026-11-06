# Shared project policies

- Keep interfaces compact and readable on desktop and mobile. Show collections as lists with clear add/edit/remove actions; open an item's editor only when selected. Verify the rendered workflow.
- Inspect existing storage, jobs, delivery/retry handling and shared mechanisms before adding infrastructure. Extend the authoritative mechanism when its lifecycle and invariants fit. Explain concrete lifecycle, ownership or behavioral differences before introducing a feature-specific mechanism; avoid speculative frameworks.
- Keep migration filenames/classes and database names short. Use concise explicit index/constraint names and check target database limits.
- Store shared rules and durable setup/architecture/limitations in Git. Keep `.agents/INDEX.md` current. Use `.agents/skills/<domain>/references/<functionality>.md` for functionality, `.agents/policies/` for shared rules and domain `references/audits/` for dated evidence. Link shared policies instead of copying them; validate reference links after reorganizing.
- Documentation changes do not authorize unrelated implementation or data changes. Mark a defect fixed only when implementation and verification establish it.
