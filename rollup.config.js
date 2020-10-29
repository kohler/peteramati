import { terser } from "rollup-plugin-terser";

export default [{
    input: "scriptsrc/main.js",
    output: {
        file: "scripts/pa.min.js",
        format: "iife",
        sourcemap: true,
        sourcemapExcludeSources: true,
        plugins: [terser()],
        name: "$pa"
    }
}];
