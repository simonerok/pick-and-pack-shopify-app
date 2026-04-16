import type { WebshipperOrder } from "../types/webshipper";

const PAGE_SIZE = 30;

function getWebshipperBaseUrl(): string {
  const account = process.env.WEBSHIPPER_ACCOUNT_NAME?.trim();
  if (!account) throw new Error("WEBSHIPPER_ACCOUNT_NAME is required");
  return `https://${encodeURIComponent(account)}.api.webshipper.io/v2`;
}

/** Check if Webshipper env is configured. */
export function isWebshipperConfigured(): boolean {
  return !!(
    process.env.WEBSHIPPER_ACCOUNT_NAME?.trim() &&
    process.env.WEBSHIPPER_ACCESS_TOKEN?.trim()
  );
}

/** Get Bearer token from env (create from Webshipper UI: Settings > Access and tokens). */
function getWebshipperToken(): string {
  const token = process.env.WEBSHIPPER_ACCESS_TOKEN?.trim();
  if (!token) throw new Error("WEBSHIPPER_ACCESS_TOKEN is required");
  return token;
}

/** JSON API document: data is array or single object; included optional. */
type JsonApiDoc = {
  data?: unknown;
  included?: Array<{ id: string; type: string; attributes?: Record<string, unknown>; relationships?: Record<string, unknown> }>;
};

function parseOrderFromResource(
  res: { id: string; type?: string; attributes?: Record<string, unknown>; relationships?: Record<string, unknown> },
  included: Map<string, Record<string, unknown>> = new Map()
): WebshipperOrder {
  const attrs = res.attributes ?? {};
  const id = typeof res.id === "number" ? res.id : parseInt(String(res.id), 10);
  if (Number.isNaN(id)) throw new Error("Webshipper order id invalid");

  // Reference: try common attribute names (Webshipper may store Shopify order number here)
  const refStr = (s: unknown): string | null =>
    typeof s === "string" && s.trim() ? s.trim() : null;
  const reference =
    refStr(attrs.reference) ||
    refStr(attrs.reference_id) ||
    refStr(attrs.referenceName) ||
    refStr(attrs.referenceId) ||
    refStr(attrs.visible_ref) ||
    refStr(attrs.external_id) ||
    refStr(attrs.order_number) ||
    refStr(attrs.name) ||
    refStr(attrs.shopify_order_id) ||
    refStr(attrs.shopify_order_number) ||
    null;

  const orderLines = Array.isArray(attrs.order_lines) ? attrs.order_lines : [];
  const firstLine = orderLines.length > 0 && orderLines[0] && typeof orderLines[0] === "object" ? (orderLines[0] as Record<string, unknown>) : null;
  const first_line_ext_ref = refStr(firstLine?.ext_ref);
  const first_line_visible_ref = refStr(firstLine?.visible_ref);
  const order_visible_ref = refStr(attrs.visible_ref);
  const order_reference = refStr(attrs.reference);
  const refFromLine = first_line_ext_ref ?? first_line_visible_ref ?? null;

  const status =
    typeof attrs.status === "string" ? attrs.status : null;
  const created_at = typeof attrs.created_at === "string" ? attrs.created_at : null;
  const updated_at = typeof attrs.updated_at === "string" ? attrs.updated_at : null;

  // Tracking: from attributes if present (e.g. tracking_code, tracking_number) or from shipments in included
  const tracking_numbers: string[] = [];
  const carrier_names: string[] = [];
  if (typeof attrs.tracking_code === "string" && attrs.tracking_code.trim()) {
    tracking_numbers.push(attrs.tracking_code.trim());
  }
  if (typeof attrs.tracking_number === "string" && attrs.tracking_number.trim()) {
    tracking_numbers.push(attrs.tracking_number.trim());
  }
  if (Array.isArray(attrs.tracking_numbers)) {
    for (const t of attrs.tracking_numbers) {
      if (typeof t === "string" && t.trim()) tracking_numbers.push(t.trim());
    }
  }
  if (typeof attrs.carrier_name === "string" && attrs.carrier_name.trim()) {
    carrier_names.push(attrs.carrier_name.trim());
  }

  // Pull tracking from included shipments (when ?include=shipments)
  const shipRel = res.relationships as Record<string, { data?: unknown }> | undefined;
  const shipData = shipRel?.shipments?.data;
  const shipList = Array.isArray(shipData) ? shipData : shipData ? [shipData] : [];
  let firstShipmentId: number | null = null;
  for (const ref of shipList) {
    const sid = ref && typeof ref === "object" && "id" in ref ? String((ref as { id: string }).id) : null;
    if (sid && firstShipmentId == null) {
      const num = parseInt(sid, 10);
      if (!Number.isNaN(num)) firstShipmentId = num;
    }
    if (sid) {
      const ship = included.get(sid);
      if (ship?.attributes && typeof ship.attributes === "object") {
        const a = ship.attributes as Record<string, unknown>;
        if (typeof a.tracking_code === "string" && a.tracking_code.trim()) tracking_numbers.push(a.tracking_code.trim());
        if (typeof a.tracking_number === "string" && a.tracking_number.trim()) tracking_numbers.push(a.tracking_number.trim());
        if (typeof a.carrier_name === "string" && a.carrier_name.trim()) carrier_names.push(a.carrier_name.trim());
      }
    }
  }

  // Match on order_visible_ref first (what Webshipper/Shopify use to link orders); then first line ext_ref/visible_ref; skip top-level reference when it's a long internal id
  const referenceForMatch = order_visible_ref || refFromLine || reference;

  return {
    id,
    reference: referenceForMatch,
    first_line_ext_ref,
    first_line_visible_ref,
    order_visible_ref,
    order_reference,
    status,
    created_at,
    updated_at,
    tracking_numbers: Array.from(new Set(tracking_numbers)),
    carrier_names: Array.from(new Set(carrier_names)),
    has_shipment: shipList.length > 0,
    shipment_id: firstShipmentId,
  };
}

/**
 * Fetch orders from Webshipper (paginated). Optionally pass include=shipments to get tracking from related shipments.
 * Returns normalized orders; use reference (or ref from first order_line ext_ref) to match to Shopify orders.
 */
export async function getWebshipperOrders(options?: {
  includeShipments?: boolean;
  maxPages?: number;
}): Promise<WebshipperOrder[]> {
  const token = getWebshipperToken();
  const base = getWebshipperBaseUrl();
  const include = options?.includeShipments ? "shipments" : undefined;
  const maxPages = options?.maxPages ?? 20;
  const orders: WebshipperOrder[] = [];
  let page = 1;

  while (page <= maxPages) {
    const url = new URL(`${base}/orders`);
    url.searchParams.set("page[number]", String(page));
    url.searchParams.set("page[size]", String(PAGE_SIZE));
    if (include) url.searchParams.set("include", include);

    const res = await fetch(url.toString(), {
      headers: { Authorization: `Bearer ${token}` },
    });

    if (!res.ok) {
      const text = await res.text();
      throw new Error(`Webshipper orders failed: ${res.status} ${text}`);
    }

    const json = (await res.json()) as JsonApiDoc;
    const data = json.data;
    const list = Array.isArray(data) ? data : data ? [data] : [];

    // Build included map: "type:id" -> resource (for JSON API included)
    const includedMap = new Map<string, Record<string, unknown>>();
    for (const inc of json.included ?? []) {
      if (inc && typeof inc === "object" && "id" in inc && "type" in inc) {
        const key = `${(inc as { type: string }).type}:${(inc as { id: string }).id}`;
        includedMap.set((inc as { id: string }).id, inc as Record<string, unknown>);
        includedMap.set(key, inc as Record<string, unknown>);
      }
    }

    for (const item of list) {
      if (item && typeof item === "object" && "id" in item && "attributes" in item) {
        try {
          const order = parseOrderFromResource(
            item as { id: string; type?: string; attributes?: Record<string, unknown>; relationships?: Record<string, unknown> },
            includedMap
          );
          orders.push(order);
        } catch (e) {
          // skip malformed entry
        }
      }
    }

    // If we got less than PAGE_SIZE, no more pages
    if (list.length < PAGE_SIZE) break;
    page += 1;
  }

  return orders;
}

/**
 * Fetch first page of orders from Webshipper and return raw JSON API response (for debugging).
 * Exposes exact attribute names and structure so you can see what we receive.
 */
export async function getWebshipperOrdersRawFirstPage(): Promise<{
  ok: boolean;
  data?: unknown[];
  firstOrderAttributes?: Record<string, unknown>;
  firstOrderRelationships?: Record<string, unknown>;
  error?: string;
}> {
  try {
    const token = getWebshipperToken();
    const base = getWebshipperBaseUrl();
    const url = `${base}/orders?page[number]=1&page[size]=5`;
    const res = await fetch(url, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!res.ok) {
      const text = await res.text();
      return { ok: false, error: `${res.status} ${text}` };
    }
    const json = (await res.json()) as JsonApiDoc;
    const data = Array.isArray(json.data) ? json.data : json.data ? [json.data] : [];
    const first = data[0];
    const firstAttrs = first && typeof first === "object" && "attributes" in first ? (first as { attributes?: Record<string, unknown> }).attributes : undefined;
    const firstRels = first && typeof first === "object" && "relationships" in first ? (first as { relationships?: Record<string, unknown> }).relationships : undefined;
    return {
      ok: true,
      data,
      firstOrderAttributes: firstAttrs,
      firstOrderRelationships: firstRels,
    };
  } catch (e) {
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
    };
  }
}

/**
 * Get or create a shipment for a Webshipper order and return the first label's PDF as base64.
 * If the order already has shipment(s), uses the first one; otherwise creates a new shipment (ships the order) then gets the label.
 * Returns { ok: true, pdfBase64 } or { ok: false, error }.
 */
export async function getLabelPdfForOrder(
  wsOrderId: number
): Promise<{ ok: true; pdfBase64: string } | { ok: false; error: string }> {
  try {
    const token = getWebshipperToken();
    const base = getWebshipperBaseUrl();

    let shipmentId: number | null = null;

    const existingRes = await fetch(
      `${base}/shipments?filter[order_id]=${encodeURIComponent(String(wsOrderId))}&page[size]=1`,
      { headers: { Authorization: `Bearer ${token}` } }
    );
    if (existingRes.ok) {
      const existingJson = (await existingRes.json()) as { data?: unknown[] };
      const list = Array.isArray(existingJson.data) ? existingJson.data : [];
      const first = list[0] as { id?: string } | undefined;
      if (first?.id) {
        shipmentId = parseInt(String(first.id), 10);
        if (Number.isNaN(shipmentId)) shipmentId = null;
      }
    }

    if (!shipmentId) {
      const createRes = await fetch(`${base}/shipments`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          data: {
            type: "shipments",
            relationships: {
              order: { data: { type: "orders", id: wsOrderId } },
            },
          },
        }),
      });
      if (!createRes.ok) {
        const text = await createRes.text();
        return { ok: false, error: `Create shipment failed: ${createRes.status} ${text}` };
      }
      const createJson = (await createRes.json()) as { data?: { id?: string } };
      const id = createJson.data?.id;
      if (!id) return { ok: false, error: "Create shipment response missing id" };
      shipmentId = parseInt(String(id), 10);
      if (Number.isNaN(shipmentId)) return { ok: false, error: "Invalid shipment id" };
    }

    const labelsRes = await fetch(`${base}/shipments/${shipmentId}/labels`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!labelsRes.ok) {
      const text = await labelsRes.text();
      return { ok: false, error: `Get labels failed: ${labelsRes.status} ${text}` };
    }
    const labelsJson = (await labelsRes.json()) as { data?: unknown[] };
    const labelsList = Array.isArray(labelsJson.data) ? labelsJson.data : [];
    const firstLabel = labelsList[0] as { id?: string } | undefined;
    if (!firstLabel?.id) return { ok: false, error: "No labels found for shipment" };

    const labelId = String(firstLabel.id);
    const pdfRes = await fetch(`${base}/labels/${labelId}?download_as=PDF`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!pdfRes.ok) {
      const text = await pdfRes.text();
      return { ok: false, error: `Get label PDF failed: ${pdfRes.status} ${text}` };
    }
    const pdfJson = (await pdfRes.json()) as { data?: { attributes?: { base64?: string } } };
    const base64 = pdfJson.data?.attributes?.base64;
    if (typeof base64 !== "string" || !base64) {
      return { ok: false, error: "Label PDF not available (base64 missing)" };
    }
    return { ok: true, pdfBase64: base64 };
  } catch (e) {
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
    };
  }
}

/** Order line from GET /v2/order_lines (minimal for return creation) */
type WebshipperOrderLine = {
  id: number;
  quantity: number;
};

/**
 * Create a return in Webshipper for the given order and return the return slip/label PDF as base64.
 * Fetches order lines and a return cause, creates the return, then retrieves the base64 return slip.
 * Returns { ok: true, pdfBase64 } or { ok: false, error }.
 */
export async function getReturnLabelPdfForOrder(
  wsOrderId: number
): Promise<{ ok: true; pdfBase64: string } | { ok: false; error: string }> {
  try {
    const token = getWebshipperToken();
    const base = getWebshipperBaseUrl();

    // 1. Get order lines for this order
    const orderLinesRes = await fetch(
      `${base}/order_lines?filter[order_id]=${encodeURIComponent(String(wsOrderId))}&page[size]=50`,
      { headers: { Authorization: `Bearer ${token}` } }
    );
    if (!orderLinesRes.ok) {
      const text = await orderLinesRes.text();
      return { ok: false, error: `Order lines failed: ${orderLinesRes.status} ${text}` };
    }
    const orderLinesJson = (await orderLinesRes.json()) as { data?: unknown[] | unknown };
    const rawData = orderLinesJson.data;
    const orderLinesList = Array.isArray(rawData) ? rawData : rawData != null ? [rawData] : [];
    if (orderLinesList.length === 0) {
      return { ok: false, error: "No order lines found for this order" };
    }
    const orderLines = orderLinesList
      .map((item) => {
        if (item && typeof item === "object" && "id" in item) {
          const rawId = (item as { id: unknown }).id;
          const id = typeof rawId === "number" ? rawId : parseInt(String(rawId), 10);
          const attrs = (item as { attributes?: Record<string, unknown> }).attributes ?? {};
          const qty = typeof attrs.quantity === "number" ? attrs.quantity : parseInt(String(attrs.quantity ?? 1), 10) || 1;
          if (!Number.isNaN(id)) return { id, quantity: qty } as WebshipperOrderLine;
        }
        return null;
      })
      .filter((x): x is WebshipperOrderLine => x != null);
    if (orderLines.length === 0) {
      return { ok: false, error: "Could not parse order lines" };
    }

    // 2. Get first return cause
    const causesRes = await fetch(`${base}/return_causes?page[size]=1`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!causesRes.ok) {
      const text = await causesRes.text();
      return { ok: false, error: `Return causes failed: ${causesRes.status} ${text}` };
    }
    const causesJson = (await causesRes.json()) as { data?: unknown[] };
    const causesList = Array.isArray(causesJson.data) ? causesJson.data : [];
    const firstCause = causesList[0] as { id?: string } | undefined;
    const causeId = firstCause?.id != null ? parseInt(String(firstCause.id), 10) : null;
    if (causeId == null || Number.isNaN(causeId)) {
      return { ok: false, error: "No return cause configured in Webshipper" };
    }

    // 3. Create return (JSON API)
    const returnLines = orderLines.map((line) => ({
      order_line_id: line.id,
      quantity: line.quantity,
      cause_id: causeId,
      cause_description: "Return requested by customer",
    }));
    const createRes = await fetch(`${base}/returns`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/vnd.api+json",
      },
      body: JSON.stringify({
        data: {
          type: "returns",
          attributes: {
            return_lines: returnLines,
          },
          relationships: {
            order: { data: { type: "orders", id: String(wsOrderId) } },
          },
        },
      }),
    });
    if (!createRes.ok) {
      const text = await createRes.text();
      return { ok: false, error: `Create return failed: ${createRes.status} ${text}` };
    }
    const createJson = (await createRes.json()) as {
      data?: { id?: string; attributes?: { base64?: string } };
    };
    const returnId = createJson.data?.id;
    if (!returnId) {
      return { ok: false, error: "Create return response missing id" };
    }

    // 4. Return slip may be in create response or we may need to GET the return (async generation)
    let base64 = createJson.data?.attributes?.base64;
    if (typeof base64 !== "string" || !base64.trim()) {
      const getRes = await fetch(`${base}/returns/${returnId}`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (getRes.ok) {
        const getJson = (await getRes.json()) as { data?: { attributes?: { base64?: string } } };
        base64 = getJson.data?.attributes?.base64 ?? "";
      }
    }
    if (typeof base64 !== "string" || !base64.trim()) {
      return {
        ok: false,
        error: "Return label not yet available. Try again in a moment or open the return in Webshipper.",
      };
    }
    return { ok: true, pdfBase64: base64 };
  } catch (e) {
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
    };
  }
}