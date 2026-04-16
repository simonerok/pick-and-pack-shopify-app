/// <reference types="vite/client" />

/**
 * App status: "Production" or "Test".
 * When not explicitly set to "Production", mutation APIs (BC, Webshipper) are disabled.
 */

export function isProduction(): boolean {
  const raw = import.meta.env.VITE_APP_STATUS?.trim()?.toLowerCase();
  return raw === "production";
}

export type AppStatus = "production" | "test";

export function getAppStatus(): AppStatus {
  return isProduction() ? "production" : "test";
}