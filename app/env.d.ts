/// <reference types="@dcloudio/types" />

interface ImportMetaEnv {
  readonly VITE_APP_BASE_URL?: string;
  readonly VITE_REMIT_LOCAL_LOGIN?: string;
  readonly VITE_REMIT_LOCAL_SMS_LOGIN?: string;
  readonly VITE_REMIT_SMS_AUTOFILL?: string;
  readonly VITE_REMIT_PUSH_ENABLED?: string;
  readonly VITE_REMIT_DEMO?: string;
  readonly VITE_REMIT_DEVICE_SIGNING?: string;
  readonly VITE_REVERB_URL?: string;
  readonly VITE_REVERB_APP_KEY?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

declare module '@dcloudio/uni-app' {
  export function onShow(callback: () => void): void;
  export function onHide(callback: () => void): void;
  export function onLoad(callback: (options?: Record<string, string>) => void): void;
  export function onBackPress(callback: (options: Page.BackPressOption) => boolean | void): void;
}

declare module '*.vue' {
  import { DefineComponent } from 'vue'
  const component: DefineComponent
  export default component
}
