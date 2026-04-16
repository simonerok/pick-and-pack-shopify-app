/**
 * Reference Vite's client types so TypeScript knows about:
 * - import.meta.env (and VITE_* variables)
 * - import.meta.hot (HMR)
 * - other Vite-specific globals
 */
/// <reference types="vite/client" />

   interface ImportMetaEnv {
    readonly VITE_APP_STATUS?: string;
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv;
  }