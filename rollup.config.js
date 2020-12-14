import { terser } from "rollup-plugin-terser";
import { eslint } from "rollup-plugin-eslint";
import resolve from "@rollup/plugin-node-resolve";
import commonjs from "@rollup/plugin-commonjs";
const fs = require("fs");
const crypto = require("crypto");


// append mtime to sourcemap
const sourcemap_mtime = {
    name: "append-sourcemap-mtime",
    writeBundle() {
        const signature = crypto.createHash("sha256").update(fs.readFileSync("scripts/pa.min.js.map")).digest("hex").substring(0, 12);
        const fd = fs.openSync("scripts/pa.min.js", "r+");
        const sz = fs.fstatSync(fd).size;
        const buf = Buffer.alloc(8192);
        const nr = fs.readSync(fd, buf, 0, 1000, Math.max(sz - 1000, 0));
        const s = buf.toString("utf8", 0, nr);
        const m = s.match(/^([^]*)(\/\/\# sourceMappingURL=pa\.min\.js\~?\.map)\s*$/);
        if (m) {
            const nbuf = Buffer.from(m[1] + m[2].replace("~", "") + "?version=" + signature + "\n", "utf8");
            fs.writeSync(fd, nbuf, 0, nbuf.length, Math.max(sz - 1000, 0));
        }
        fs.closeSync(fd);
    }
};

export default [{
    input: "scriptsrc/main.js",
    plugins: [resolve(), commonjs(), eslint()],
    output: {
        file: "scripts/pa.min.js",
        format: "iife",
        sourcemap: true,
        sourcemapExcludeSources: true,
        plugins: [
            terser(),
            sourcemap_mtime
        ],
        name: "$pa"
    }
}];
