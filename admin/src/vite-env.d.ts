/// <reference types="vite/client" />

export {}

declare global {
  namespace JSX {
    interface IntrinsicElements {
      [elem: string]: any
    }
  }
  interface ImportMetaEnv {
    readonly VITE_API_BASE?: string
    readonly VITE_PROXY_TARGET?: string
    readonly VITE_TINYMCE_LICENSE_KEY?: string
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, unknown>
  export default component
}
