(function () {
  var defaultThemeMode = "light";
  var themeMode;
  if (document.documentElement) {
    if (document.documentElement.hasAttribute("data-bs-theme-mode")) {
      themeMode = document.documentElement.getAttribute("data-bs-theme-mode");
    } else {
      themeMode =
        localStorage.getItem("data-bs-theme") !== null
          ? localStorage.getItem("data-bs-theme")
          : defaultThemeMode;
    }
    if (themeMode === "system") {
      themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    }
    document.documentElement.setAttribute("data-bs-theme", themeMode);
  }
})();
