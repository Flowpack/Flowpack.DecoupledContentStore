
module.exports = {
  purge: {
      enabled: !Boolean(process.env.DEV),
      content: [
          'Resources/Private/BackendFusion/**/*.fusion'
      ]
  },
  darkMode: false, // or 'media' or 'class'
  theme: {
    extend: {},
  },
  variants: {
    extend: {},
  },
  plugins: [],
}
