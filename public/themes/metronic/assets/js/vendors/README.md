# Vendors (bundled assets)

Heavy third-party code lives under `assets/plugins/` (Metronic convention), not in this folder.

| Path | Role |
|------|------|
| `assets/plugins/global/plugins.bundle.js` | jQuery, Bootstrap, Moment, Select2, Axios, etc. |
| `assets/plugins/custom/fullcalendar/` | Calendar widgets |
| `assets/plugins/custom/datatables/` | DataTables |
| `assets/js/scripts.bundle.js` | Metronic `data-kt-*` initialisation |

For Vue + Vite, replace these bundles with npm packages and route-level `import()` where possible.
