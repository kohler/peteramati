const path = require("path");

module.exports = {
    entry: "./scriptsrc/main.js",
    mode: "production",
    devtool: "source-map",
    output: {
        path: path.resolve(__dirname, "scripts"),
        filename: "pa.min.js"
    }
};
