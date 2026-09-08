import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import fs from 'fs'
import dotenv from 'dotenv'
dotenv.config()

export default defineConfig(({ command }) => ({
  resolve: {
    alias: {
      axios: path.resolve(__dirname, 'node_modules/axios/dist/axios.js'),
    }
  },
  plugins: [
    laravel({
      input: [
        'resources/css/pdf.css',
        'resources/css/ckeditor.css',
        'resources/js/app.js',
      ],
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          includeAbsolute: false,
        },
      },
    }),
    // flare({
    //   key: 'imlZ812f4qb42S1yhjvJGl1zmlOuZuoO',
    // })
  ],
  server: command === 'serve' ? detectServerConfig() : undefined,
}))

function detectServerConfig () {
  if (
    (process.env.APP_ENV && process.env.APP_ENV !== 'production') ||
    process.env.NODE_ENV !== 'production'
  ) {
    const url = new URL(process.env.APP_URL || 'http://localhost')
    if (!process.env.APP_SSL_KEY || !process.env.APP_SSL_CERT) {
      return { host: '0.0.0.0', hmr: { host: url.hostname } }
    }

    // config.stats = {
    //   children: true
    // }

    return {
      host: url.host,
      hmr: {
        host: url.host,
      },
      https: {
        key: fs.readFileSync(process.env.APP_SSL_KEY),
        cert: fs.readFileSync(process.env.APP_SSL_CERT),
      },
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, PATCH, OPTIONS",
        "Access-Control-Allow-Headers": "X-Requested-With, content-type, Authorization"
      },
    }
  }

  return {}
}
