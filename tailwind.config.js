import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                'primary': 'rgb(var(--primary))',
                'primary-inverse': 'rgb(var(--primary-inverse))',
                'primary-hover': 'rgb(var(--primary-hover))',
                'primary-active-color': 'rgb(var(--primary-active-color))',

                'primary-highlight': 'rgb(var(--primary)/var(--primary-highlight-opacity))',
                'primary-highlight-inverse': 'rgb(var(--primary-highlight-inverse))',
                'primary-highlight-hover': 'rgb(var(--primary)/var(--primary-highlight-hover-opacity))',

                'primary-50': 'rgb(var(--primary-50))',
                'primary-100': 'rgb(var(--primary-100))',
                'primary-200': 'rgb(var(--primary-200))',
                'primary-300': 'rgb(var(--primary-300))',
                'primary-400': 'rgb(var(--primary-400))',
                'primary-500': 'rgb(var(--primary-500))',
                'primary-600': 'rgb(var(--primary-600))',
                'primary-700': 'rgb(var(--primary-700))',
                'primary-800': 'rgb(var(--primary-800))',
                'primary-900': 'rgb(var(--primary-900))',
                'primary-950': 'rgb(var(--primary-950))',

                'surface-0': 'rgb(var(--surface-0))',
                'surface-50': 'rgb(var(--surface-50))',
                'surface-100': 'rgb(var(--surface-100))',
                'surface-200': 'rgb(var(--surface-200))',
                'surface-300': 'rgb(var(--surface-300))',
                'surface-400': 'rgb(var(--surface-400))',
                'surface-500': 'rgb(var(--surface-500))',
                'surface-600': 'rgb(var(--surface-600))',
                'surface-700': 'rgb(var(--surface-700))',
                'surface-800': 'rgb(var(--surface-800))',
                'surface-900': 'rgb(var(--surface-900))',
                'surface-950': 'rgb(var(--surface-950))',

                /* POS terminal skin, scoped to .pos (see resources/css/pos.css) */
                'pos-canvas': 'rgb(var(--pos-canvas) / <alpha-value>)',
                'pos-panel': 'rgb(var(--pos-panel) / <alpha-value>)',
                'pos-panel-2': 'rgb(var(--pos-panel-2) / <alpha-value>)',
                'pos-line': 'rgb(var(--pos-line) / <alpha-value>)',
                'pos-line-strong': 'rgb(var(--pos-line-strong) / <alpha-value>)',
                'pos-text': 'rgb(var(--pos-text) / <alpha-value>)',
                'pos-muted': 'rgb(var(--pos-muted) / <alpha-value>)',
                'pos-accent': 'rgb(var(--pos-accent) / <alpha-value>)',
                'pos-accent-ink': 'rgb(var(--pos-accent-ink) / <alpha-value>)',
                'pos-good': 'rgb(var(--pos-good) / <alpha-value>)',
                'pos-warn': 'rgb(var(--pos-warn) / <alpha-value>)',
                'pos-bad': 'rgb(var(--pos-bad) / <alpha-value>)'
            },
            borderRadius: {
                pos: 'var(--pos-radius)',
            },
            minHeight: {
                'pos-touch': 'var(--pos-touch)',
                'pos-row': 'var(--pos-row)',
                'pos-commit': 'var(--pos-commit)',
            },
            fontFamily: {
                main: ['Century Gothic', ...defaultTheme.fontFamily.sans],
                logo: ['Iris UPC', ...defaultTheme.fontFamily.sans],
            },
            screens: {
                'xs': '370px',
            },
        },
    },

    plugins: [forms],
};
