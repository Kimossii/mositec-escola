// Tema claro/escuro: mesma persistência que o ThemeModeMenu do cabeçalho (localStorage + atributos do <html>).
export function temaActual() {
    return localStorage.getItem('kt_theme_mode_value') || 'light';
}

export function definirTema(modo) {
    localStorage.setItem('kt_theme_mode_value', modo);
    localStorage.setItem('kt_theme_mode_switch', modo);
    localStorage.setItem('data-bs-theme', modo);

    document.documentElement.setAttribute('data-bs-theme', modo);
    document.documentElement.setAttribute('data-kt-theme', modo);

    if (window.KTThemeMode) {
        window.KTThemeMode.setMode(modo);
    }
}
