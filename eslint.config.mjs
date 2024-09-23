import globals from "globals";
import pluginJs from "@eslint/js";


export default [
  pluginJs.configs.recommended,
  {
    ignores: ["*.config.mjs", "*.config.js", "**/*.min.js", "scripts/xterm.js"]
  },
  {
    files: ["**/*.js"],
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.jquery,
        hljs: "readonly",
        siteinfo: "readonly",
        markdownit: "readonly",
        markdownit_katex: "readonly",
        Terminal: "readonly",
        "$pa": "writable"
      },
      parserOptions: {
        ecmaFeatures: {
          impliedStrict: true
        }
      }
    },
    rules: {
      "no-empty": [ "error", { allowEmptyCatch: true } ],
      "no-control-regex": [ "off" ]
    }
  }
];
