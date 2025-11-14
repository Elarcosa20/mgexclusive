const { defineConfig } = require('@vue/cli-service');
const webpack = require('webpack');

const resolveProxyTarget = () => {
  const candidates = [
    process.env.API_PROXY_TARGET,
    process.env.VITE_API_BASE_URL,
    process.env.VUE_APP_API_BASE_URL,
    'http://localhost:8000',
  ];

  for (const candidate of candidates) {
    if (candidate) return candidate.replace(/\/$/, '');
  }

  return 'http://localhost:8000';
};

module.exports = defineConfig({
  transpileDependencies: true,
  devServer: {
    proxy: {
      '^/api': {
        target: resolveProxyTarget(),
        changeOrigin: true,
        ws: false,
        logLevel: 'warn',
      },
      '^/broadcasting': {
        target: resolveProxyTarget(),
        changeOrigin: true,
        ws: true,
        logLevel: 'warn',
      },
    },
  },
  configureWebpack: {
    plugins: [
      new webpack.DefinePlugin({
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false'
      })
    ]
  }
});
