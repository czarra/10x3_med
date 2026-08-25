---
project: DiaGuide
repo: czarra/10x3_med
generated: 2026-08-23
source: context/foundation/roadmap.md (v1)
---

# GitHub Issues — mirror roadmapy

Statyczne odwzorowanie `context/foundation/roadmap.md` na utworzone issues w
`czarra/10x3_med`, żeby nawigować z ID roadmapy (`S-03`) wprost do ticketu i
odwrotnie. Nie jest generowane automatycznie — patrz "Odświeżanie" na końcu.

## Mapowanie

| Roadmap ID | Change ID                    | Issue                                                  | Etykiety           | Zależy od (issue) | Status (roadmap) | Stan (GitHub) |
| ---------- | ------------------------------ | -------------------------------------------------------- | -------------------- | -------------------- | ------------------- | ---------------- |
| F-01       | auth-scaffold                  | [#1](https://github.com/czarra/10x3_med/issues/1)         | foundation            | —                     | done                 | closed            |
| F-02       | deploy-pipeline-live            | [#2](https://github.com/czarra/10x3_med/issues/2)         | foundation            | —                     | ready                | open              |
| S-01       | patient-onboarding              | [#3](https://github.com/czarra/10x3_med/issues/3)         | slice                 | #1                    | done                 | closed            |
| S-02       | log-diary-entry                  | [#4](https://github.com/czarra/10x3_med/issues/4)         | slice                 | #3                    | done                 | closed            |
| S-03       | insulin-ww-ratio-suggestion      | [#5](https://github.com/czarra/10x3_med/issues/5)         | slice, north-star     | #4                    | proposed             | open              |
| S-04       | activity-hypoglycemia-warning    | [#6](https://github.com/czarra/10x3_med/issues/6)         | slice                 | #4                    | proposed             | open              |
| S-05       | diary-history-view               | [#7](https://github.com/czarra/10x3_med/issues/7)         | slice                 | #4                    | proposed             | open              |
| S-06       | edit-delete-diary-entry          | [#8](https://github.com/czarra/10x3_med/issues/8)         | slice                 | #4                    | proposed             | open              |
| S-07       | export-diary-history              | [#9](https://github.com/czarra/10x3_med/issues/9)         | slice                 | #7, #5                | proposed             | open              |

Gwiazda przewodnia (north star) roadmapy: **S-03 → [#5](https://github.com/czarra/10x3_med/issues/5)**.

## Odświeżanie

```bash
gh issue list --repo czarra/10x3_med --state all \
  --json number,title,labels,state \
  --template '{{range .}}#{{.number}}\t{{.state}}\t{{range .labels}}{{.name}} {{end}}\t{{.title}}\n{{end}}'
```

Porównaj wynik z kolumną "Stan (GitHub)" powyżej i z `## At a glance` w
`roadmap.md` (kolumna "Status") — jeśli się rozjadą (np. `/10x-plan` przesunęło
Status na `planning`, albo ktoś zamknął issue na GitHubie), zaktualizuj ten
plik ręcznie. To świadomie plik statyczny, nie auto-synchronizowany.
