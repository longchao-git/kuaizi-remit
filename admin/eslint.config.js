import js from '@eslint/js'
import vue from 'eslint-plugin-vue'
import ts from '@typescript-eslint/eslint-plugin'
import tsParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import globals from 'globals'

export default [
  { ignores: ['dist/**', 'node_modules/**', 'public/**'] },
  js.configs.recommended,
  ...vue.configs['flat/essential'],
  {
    files: ['src/**/*.{ts,tsx,vue}'],
    plugins: { '@typescript-eslint': ts },
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tsParser,
        ecmaVersion: 'latest',
        sourceType: 'module',
        extraFileExtensions: ['.vue']
      },
      globals: { ...globals.browser, ...globals.node }
    },
    rules: {
      ...ts.configs['eslint-recommended'].overrides[0].rules,
      "no-unused-vars": "off",
      "@typescript-eslint/no-unused-vars": [
        "error",
        {
          "argsIgnorePattern": "^_",
          "varsIgnorePattern": "^_"
        }
      ],
      "no-restricted-imports": [
        "error",
        {
          "paths": [
            {
              "name": "@/request",
              "message": "禁止目录导入，请显式指向具体文件（如 @/request/http）"
            },
            {
              "name": "@/router",
              "message": "禁止目录导入，请显式指向具体文件（如 @/router/router）"
            },
            {
              "name": "@/stores",
              "message": "禁止目录导入，请显式指向具体文件（如 @/stores/pinia）"
            },
            {
              "name": "@/shared/types/api",
              "message": "禁止 barrel 入口，请显式指向具体文件（如 @/shared/types/api/PageResult）"
            },
            {
              "name": "@/utils/format",
              "message": "禁止 barrel 入口，请显式指向具体文件（如 @/utils/date、@/utils/error）"
            },
            {
              "name": "../domain",
              "message": "禁止目录导入（domain），请显式指向具体类型文件"
            },
            {
              "name": "./domain",
              "message": "禁止目录导入（domain），请显式指向具体类型文件"
            },
            {
              "name": "./index",
              "message": "禁止 index 入口，请显式指向具体文件"
            },
            {
              "name": "../index",
              "message": "禁止 index 入口，请显式指向具体文件"
            }
          ],
          "patterns": [
            {
              "group": [
                "**/index.ts",
                "**/index.tsx",
                "**/index.js",
                "**/index.jsx",
                "**/index.mjs",
                "**/index.cjs",
                "**/index.vue"
              ],
              "message": "禁止 index 入口，请显式指向具体文件"
            }
          ]
        }
      ],
      "no-restricted-syntax": [
        "error",
        {
          "selector": "ExportAllDeclaration",
          "message": "禁止 barrel export：不要使用 export * from"
        },
        {
          "selector": "ExportNamedDeclaration[source!=null]",
          "message": "禁止 barrel export：不要使用 export { ... } from"
        }
      ],
      "vue/max-attributes-per-line": "off",
      "vue/attributes-order": "off",
      "vue/html-indent": "off",
      "vue/html-self-closing": "off",
      "vue/singleline-html-element-content-newline": "off",
      "vue/require-default-prop": "off",
      "vue/no-v-model-argument": "off",
      "vue/multi-word-component-names": "off"
    }
  },
  {
    files: ['src/**/*.{ts,tsx}'],
    languageOptions: { parser: tsParser }
  }
]
