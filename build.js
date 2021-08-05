const esbuild = require("esbuild");
const tailwindcss = require("tailwindcss");
const autoprefixer = require("autoprefixer");
const postCssPlugin = require("esbuild-plugin-postcss2").default;

const watchMode = Boolean(process.env.DEV);
esbuild
    .build({
        entryPoints: ["Resources/Private/Js/index.js"],
        bundle: true,
        outfile: "Resources/Public/BackendCompiled/out.js",
        watch: watchMode,
        plugins: [
            postCssPlugin({
                plugins: [tailwindcss, autoprefixer],
            }),
        ],
    })
    .catch((e) => console.error(e.message));