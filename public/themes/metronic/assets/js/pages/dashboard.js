/**
 * Dashboard-only initialisation (FullCalendar, AmCharts, DataTables, etc.).
 * Intentionally minimal until Vue migration replaces Metronic widget bootstrapping.
 */
(function () {
  if (typeof document === "undefined") return;
  document.documentElement.setAttribute("data-theme-page", "dashboard");
})();
