import type { BCSalesOrder, BCCompany } from "../types/business-central";

const TOKEN_URL = (tenant: string) =>
  `https://login.microsoftonline.com/${tenant}/oauth2/v2.0/token`;
const BC_SCOPE = "https://api.businesscentral.dynamics.com/.default";

function getBCBaseUrl(tenantId: string, environment: string): string {
  return `https://api.businesscentral.dynamics.com/v2.0/${encodeURIComponent(tenantId)}/${encodeURIComponent(environment)}/api/v2.0`;
}

/** Get OAuth2 access token using client credentials (server-side only). */
export async function getBCAccessToken(): Promise<string> {
  const tenantId = process.env.BC_TENANT_ID;
  const clientId = process.env.BC_CLIENT_ID;
  const clientSecret = process.env.BC_CLIENT_SECRET;

  if (!tenantId || !clientId || !clientSecret) {
    throw new Error("BC_TENANT_ID, BC_CLIENT_ID, and BC_CLIENT_SECRET are required for Business Central");
  }

  const body = new URLSearchParams({
    grant_type: "client_credentials",
    client_id: clientId,
    client_secret: clientSecret,
    scope: BC_SCOPE,
  });

  const res = await fetch(TOKEN_URL(tenantId), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: body.toString(),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`BC token failed: ${res.status} ${text}`);
  }

  const data = (await res.json()) as { access_token?: string };
  if (!data.access_token) throw new Error("BC token response missing access_token");
  return data.access_token;
}

/** Fetch first page of companies (used to get company id if not in env). */
export async function getBCCompanies(accessToken: string): Promise<BCCompany[]> {
  const tenantId = process.env.BC_TENANT_ID;
  const environment = process.env.BC_ENVIRONMENT || "production";
  if (!tenantId) throw new Error("BC_TENANT_ID is required");

  const base = getBCBaseUrl(tenantId, environment);
  const res = await fetch(`${base}/companies?$top=20`, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`BC companies failed: ${res.status} ${text}`);
  }

  const json = (await res.json()) as { value?: BCCompany[] };
  return json.value ?? [];
}

/** Fetch sales orders for a company. Uses $top and optional $filter; returns all pages if needed. */
export async function getBCSalesOrders(
  accessToken: string,
  companyId: string
): Promise<BCSalesOrder[]> {
  const tenantId = process.env.BC_TENANT_ID;
  const environment = process.env.BC_ENVIRONMENT || "production";
  if (!tenantId) throw new Error("BC_TENANT_ID is required");

  const base = getBCBaseUrl(tenantId, environment);
  const orders: BCSalesOrder[] = [];
  let nextUrl: string | null = `${base}/companies(${companyId})/salesOrders?$top=250&$select=id,number,externalDocumentNumber,orderDate,requestedDeliveryDate,status,customerName,totalAmountIncludingTax,fullyShipped,lastModifiedDateTime`;

  while (nextUrl) {
    const res = await fetch(nextUrl, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });

    if (!res.ok) {
      const text = await res.text();
      throw new Error(`BC sales orders failed: ${res.status} ${text}`);
    }

    const json = (await res.json()) as {
      value?: Array<{
        id: string;
        number?: string;
        externalDocumentNumber?: string | null;
        orderDate?: string | null;
        requestedDeliveryDate?: string | null;
        status?: string;
        customerName?: string | null;
        totalAmountIncludingTax?: number | null;
        fullyShipped?: boolean;
        lastModifiedDateTime?: string | null;
      }>;
      "@odata.nextLink"?: string;
    };

    for (const o of json.value ?? []) {
      orders.push({
        id: o.id,
        number: o.number ?? "",
        externalDocumentNumber: o.externalDocumentNumber ?? null,
        orderDate: o.orderDate ?? null,
        requestedDeliveryDate: o.requestedDeliveryDate ?? null,
        status: o.status ?? "",
        customerName: o.customerName ?? null,
        totalAmountIncludingTax: o.totalAmountIncludingTax ?? null,
        fullyShipped: o.fullyShipped ?? false,
        lastModifiedDateTime: o.lastModifiedDateTime ?? null,
      });
    }

    nextUrl = json["@odata.nextLink"] ?? null;
  }

  return orders;
}

/** Fetch a single sales order by ID with all properties (no $select). Optionally expand navigations (e.g. salesOrderLines). */
export async function getBCSalesOrderById(
  accessToken: string,
  companyId: string,
  orderId: string,
  options?: { expand?: string[] }
): Promise<Record<string, unknown>> {
  const tenantId = process.env.BC_TENANT_ID;
  const environment = process.env.BC_ENVIRONMENT || "production";
  if (!tenantId) throw new Error("BC_TENANT_ID is required");

  const base = getBCBaseUrl(tenantId, environment);
  let url = `${base}/companies(${companyId})/salesOrders(${orderId})`;
  if (options?.expand?.length) {
    url += `?$expand=${options.expand.join(",")}`;
  }
  const res = await fetch(url, {
    headers: { Authorization: `Bearer ${accessToken}` },
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`BC sales order by id failed: ${res.status} ${text}`);
  }

  return (await res.json()) as Record<string, unknown>;
}

/** Get the earliest shipment date from a sales order's lines (from $expand=salesOrderLines). Returns YYYY-MM-DD or null. */
export function getShipmentDateFromBCOrder(order: Record<string, unknown>): string | null {
  const raw = order.salesOrderLines;
  const lines = Array.isArray(raw)
    ? raw
    : (raw && typeof raw === "object" && "value" in raw && Array.isArray((raw as { value: unknown[] }).value))
      ? (raw as { value: unknown[] }).value
      : [];
  if (lines.length === 0) return null;
  let earliest: string | null = null;
  for (const line of lines) {
    const lineObj = line as Record<string, unknown>;
    const d = lineObj.shipmentDate;
    if (typeof d === "string" && d.trim()) {
      if (!earliest || d < earliest) earliest = d;
    }
  }
  return earliest;
}

/** Fetch a BC sales order with salesOrderLines expanded and return the earliest line shipment date, or null. */
export async function getBCOrderShipmentDate(
  accessToken: string,
  companyId: string,
  orderId: string
): Promise<string | null> {
  const order = await getBCSalesOrderById(accessToken, companyId, orderId, {
    expand: ["salesOrderLines"],
  });
  return getShipmentDateFromBCOrder(order);
}

/**
 * Fetch purchase order lines for the company and return a map from item number (lineObjectNumber)
 * to the earliest expected receipt date (YYYY-MM-DD). Only includes lines with receiveQuantity > 0
 * and a valid expectedReceiptDate.
 * BC API requires a parent purchase order to get lines, so we fetch purchase orders then each order's lines.
 */
export async function getBCExpectedReceiptByItem(
  accessToken: string,
  companyId: string
): Promise<Map<string, string>> {
  const tenantId = process.env.BC_TENANT_ID;
  const environment = process.env.BC_ENVIRONMENT || "production";
  if (!tenantId) throw new Error("BC_TENANT_ID is required");

  const base = getBCBaseUrl(tenantId, environment);
  const byItem = new Map<string, string>();

  // BC requires an Id or Document Id to get lines; fetch POs first, then lines per PO
  let ordersUrl: string | null = `${base}/companies(${encodeURIComponent(companyId)})/purchaseOrders?$top=100&$select=id`;
  const orderIds: string[] = [];

  while (ordersUrl) {
    const res = await fetch(ordersUrl, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(`BC purchase orders failed: ${res.status} ${text}`);
    }
    const json = (await res.json()) as { value?: Array<{ id?: string }>; "@odata.nextLink"?: string };
    for (const o of json.value ?? []) {
      if (o.id) orderIds.push(o.id);
    }
    ordersUrl = json["@odata.nextLink"] ?? null;
  }

  const select = "lineObjectNumber,expectedReceiptDate,receiveQuantity";
  for (const poId of orderIds) {
    const linesUrl = `${base}/companies(${encodeURIComponent(companyId)})/purchaseOrders(${encodeURIComponent(poId)})/purchaseOrderLines?$top=500&$select=${select}`;
    const res = await fetch(linesUrl, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(`BC purchase order lines failed: ${res.status} ${text}`);
    }
    const json = (await res.json()) as {
      value?: Array<{
        lineObjectNumber?: string | null;
        expectedReceiptDate?: string | null;
        receiveQuantity?: number | null;
      }>;
    };
    for (const line of json.value ?? []) {
      const qty = line.receiveQuantity ?? 0;
      const dateStr = line.expectedReceiptDate;
      const itemNo = line.lineObjectNumber?.trim();
      if (!itemNo || qty <= 0 || typeof dateStr !== "string" || !dateStr.trim()) continue;
      const date = dateStr.split("T")[0];
      if (!date || date.length !== 10) continue;
      const existing = byItem.get(itemNo);
      if (!existing || date < existing) byItem.set(itemNo, date);
    }
  }

  return byItem;
}

/**
 * Create a sales order line in Business Central.
 * For a Comment line, only lineType and description are required.
 * @see https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/api-reference/v2.0/api/dynamics_salesorderline_create
 */
export async function createBCSalesOrderLine(
  accessToken: string,
  companyId: string,
  salesOrderId: string,
  body: { lineType: string; description: string }
): Promise<Record<string, unknown>> {
  const tenantId = process.env.BC_TENANT_ID;
  const environment = process.env.BC_ENVIRONMENT || "production";
  if (!tenantId) throw new Error("BC_TENANT_ID is required");

  const base = getBCBaseUrl(tenantId, environment);
  const url = `${base}/companies(${encodeURIComponent(companyId)})/salesOrders(${encodeURIComponent(salesOrderId)})/salesOrderLines`;

  const res = await fetch(url, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${accessToken}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`BC create sales order line failed: ${res.status} ${text}`);
  }

  return (await res.json()) as Record<string, unknown>;
}

/** Check if BC env is configured (so we can skip BC calls when not set). */
export function isBCConfigured(): boolean {
  return !!(
    process.env.BC_TENANT_ID &&
    process.env.BC_CLIENT_ID &&
    process.env.BC_CLIENT_SECRET
  );
}

/** Resolve which company to use from env (BC_COMPANY_ID, BC_COMPANY_NAME) or first in list. */
export async function resolveBCCompany(
  accessToken: string
): Promise<{ companyId: string; company: BCCompany } | null> {
  const companies = await getBCCompanies(accessToken);
  if (companies.length === 0) return null;

  const companyIdEnv = process.env.BC_COMPANY_ID?.trim() || null;
  if (companyIdEnv) {
    const byId = companies.find((c) => c.id === companyIdEnv);
    if (byId) return { companyId: byId.id, company: byId };
  }

  const companyName = process.env.BC_COMPANY_NAME?.trim() || null;
  if (companyName) {
    const match = companies.find(
      (c) =>
        c.name?.toLowerCase() === companyName.toLowerCase() ||
        (c.displayName?.toLowerCase() === companyName.toLowerCase())
    );
    if (match) return { companyId: match.id, company: match };
  }

  return { companyId: companies[0].id, company: companies[0] };
}

/**
 * Fetch all BC sales orders for the company from env (BC_COMPANY_ID or BC_COMPANY_NAME) or first company.
 * Returns [] when BC is not configured.
 */
export async function fetchBCSalesOrders(): Promise<BCSalesOrder[]> {
  if (!isBCConfigured()) return [];

  const token = await getBCAccessToken();
  const resolved = await resolveBCCompany(token);
  if (!resolved) return [];

  return getBCSalesOrders(token, resolved.companyId);
}