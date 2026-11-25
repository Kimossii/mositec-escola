/** «2026-06-15» → «15/06/2026» (dias, sem conversões de fuso). */
export const formatarDia = (dia) => (dia ? dia.split('-').reverse().join('/') : '');

/** «2026-06-15» → «15/06». */
export const formatarDiaCurto = (dia) => (dia ? dia.split('-').reverse().slice(0, 2).join('/') : '');

/** Hoje (fuso do navegador) em AAAA-MM-DD, só como valor inicial; o servidor valida no fuso da escola. */
export const hojeIso = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** Dia seguinte a uma data AAAA-MM-DD. */
export const diaSeguinte = (dia) => {
    const [a, m, d] = dia.split('-').map(Number);
    const seguinte = new Date(a, m - 1, d + 1);
    return `${seguinte.getFullYear()}-${String(seguinte.getMonth() + 1).padStart(2, '0')}-${String(seguinte.getDate()).padStart(2, '0')}`;
};

export const DIAS = [
    { valor: 1, curto: 'Seg', nome: 'Segunda-feira' },
    { valor: 2, curto: 'Ter', nome: 'Terça-feira' },
    { valor: 3, curto: 'Qua', nome: 'Quarta-feira' },
    { valor: 4, curto: 'Qui', nome: 'Quinta-feira' },
    { valor: 5, curto: 'Sex', nome: 'Sexta-feira' },
    { valor: 6, curto: 'Sáb', nome: 'Sábado' },
];

export const SABADO = 6;
