import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';

/**
 * Config de ESLint (flat, v9). Enfoque: bugs reales, no estilo.
 * - js.recommended: undefined vars, casos imposibles, etc.
 * - react-hooks: deps faltantes en useEffect, hooks condicionales (clase de bug real).
 */
export default [
  { ignores: ['dist/**', 'e2e/**', 'node_modules/**'] },
  js.configs.recommended,
  {
    files: ['src/**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: { ...globals.browser },
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    plugins: { 'react-hooks': reactHooks },
    // Solo las reglas clásicas de alta señal. NO spread de v6 (trae reglas experimentales
    // del React Compiler que flaggean patrones idiomáticos: new Date() en render, etc.).
    rules: {
      // Hook condicional / en loop = bug real (orden de hooks).
      'react-hooks/rules-of-hooks': 'error',
      // Deps faltantes (posible stale closure). Warn: hay omisiones deliberadas con disable.
      'react-hooks/exhaustive-deps': 'warn',
      // Dead code. Ignora componentes (Mayúsc) y args con _.
      'no-unused-vars': ['warn', { varsIgnorePattern: '^[A-Z_]', argsIgnorePattern: '^_' }],
      // Catch vacío es deliberado en varios flujos (logout/simulate best-effort).
      'no-empty': ['warn', { allowEmptyCatch: true }],
    },
  },
];
