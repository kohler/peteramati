import { terser } from "rollup-plugin-terser";
const fs = require("fs");

// append mtime to sourcemap
const sourcemap_mtime = {
    name: "append-sourcemap-mtime",
    writeBundle() {
        const mtime = Math.trunc(fs.statSync("scripts/pa.min.js.map").mtime.getTime() / 1000);
        const fd = fs.openSync("scripts/pa.min.js", "r+");
        const sz = fs.fstatSync(fd).size;
        const buf = Buffer.alloc(8192);
        const nr = fs.readSync(fd, buf, 0, 1000, Math.max(sz - 1000, 0));
        const s = buf.toString("utf8", 0, nr);
        const m = s.match(/^([^]*\/\/\# sourceMappingURL=pa\.min\.js\.map)\s*$/);
        if (m) {
            const nbuf = Buffer.from(m[1] + "?mtime=" + mtime + "\n", "utf8");
            fs.writeSync(fd, nbuf, 0, nbuf.length, Math.max(sz - 1000, 0));
        }
        fs.closeSync(fd);
    }
};

export default [{
    input: "scriptsrc/main.js",
    output: {
        file: "scripts/pa.min.js",
        format: "iife",
        sourcemap: true,
        sourcemapExcludeSources: true,
        plugins: [terser(), sourcemap_mtime],
        name: "$pa"
    }
}];
