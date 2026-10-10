/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/Views/**/*.twig',
    './themes/*/templates/**/*.twig',
    './themes/*/theme.json',
    './plugins/*/**/*.twig',
  ],
  safelist: [
    // Admin badge/status pills are chosen from data at runtime.
    'bg-green-100', 'text-green-700', 'bg-amber-100', 'text-amber-700',
    'bg-red-100', 'text-red-700', 'bg-slate-100', 'text-slate-600',
    'bg-indigo-100', 'text-indigo-700',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        serif: ['Fraunces', 'Georgia', 'ui-serif', 'serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'monospace'],
      },
      maxWidth: {
        content: '1024px',
      },
      colors: {
        // Theme-driven tokens. Public themes override these CSS variables;
        // admin uses the defaults declared in resources/css/app.css.
        bg: 'rgb(var(--c-bg) / <alpha-value>)',
        surface: 'rgb(var(--c-surface) / <alpha-value>)',
        surface2: 'rgb(var(--c-surface-2) / <alpha-value>)',
        line: 'rgb(var(--c-border) / <alpha-value>)',
        ink: 'rgb(var(--c-ink) / <alpha-value>)',
        muted: 'rgb(var(--c-muted) / <alpha-value>)',
        brand: 'rgb(var(--c-brand) / <alpha-value>)',
        brandink: 'rgb(var(--c-brand-ink) / <alpha-value>)',
        accent: 'rgb(var(--c-accent) / <alpha-value>)',
      },
    },
  },
  plugins: [],
};
