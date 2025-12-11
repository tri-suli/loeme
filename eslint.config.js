// ESLint 9 flat config (ESM) – Vue 3 + TypeScript
// Note: package.json has "type": "module", so this file must use ESM syntax.
import vue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
import tsParser from '@typescript-eslint/parser';

export default [
    // Global ignores
    {
        ignores: ['vendor/**', 'public/**', 'node_modules/**', 'storage/**', 'bootstrap/**'],
    },
    // Project rules
    {
        files: ['resources/js/**/*.{js,ts,vue}'],
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tsParser,
                ecmaVersion: 'latest',
                sourceType: 'module',
                extraFileExtensions: ['.vue'],
            },
        },
        plugins: {
            vue,
        },
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
];
