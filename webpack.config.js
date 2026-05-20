const path = require("path");

module.exports = {
    entry: "./scriptsrc/main.js",
    mode: "production",
    devtool: "source-map",
    output: {
        path: path.resolve(__dirname, "scripts"),
        filename: "pa.min.js",
        // Async chunks (Shiki runtime + grammars) use stable names so the
        // committed build has a fixed, predictable set of files. publicPath
        // "auto" resolves them relative to pa.min.js's own URL at runtime,
        // which is correct even when scripts are served from a CDN.
        chunkFilename: "[name].min.js",
        publicPath: "auto",
        // Remove stale webpack outputs (e.g. renamed chunks) on each build,
        // but preserve the hand-maintained vendor scripts in scripts/.
        clean: {
            keep: (asset) => /^(?:jquery|markdown-it\.min\.js|katex\.min\.js|xterm\.js)/.test(asset)
        }
    },
    optimization: {
        // Keep each dynamic import() self-contained so the build emits exactly
        // shiki.min.js and shiki-fallback.min.js (no auto-named shared chunks).
        splitChunks: false
    }
};
