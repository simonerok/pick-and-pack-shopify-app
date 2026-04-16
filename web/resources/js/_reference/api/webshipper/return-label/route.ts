import { NextResponse } from "next/server";
import { isProduction } from "@/app/lib/app-status";
import { getReturnLabelPdfForOrder } from "@/app/lib/webshipper";

/**
 * GET /api/webshipper/return-label?orderId=123
 * Creates a return in Webshipper for the given order and returns the return label/slip as PDF (base64).
 * Response: { ok: true, pdfBase64 } or { ok: false, error }.
 * Disabled when VITE_APP_STATUS is not "Production".
 */
export async function GET(request: Request) {
  if (!isProduction()) {
    return NextResponse.json(
      { ok: false, error: "App is in Test mode; return label creation is disabled. Set VITE_APP_STATUS=Production to enable." },
      { status: 403 }
    );
  }

  const url = new URL(request.url);
  const orderIdParam = url.searchParams.get("orderId");
  const orderId = orderIdParam ? parseInt(orderIdParam, 10) : NaN;
  if (Number.isNaN(orderId) || orderId < 1) {
    return NextResponse.json(
      { ok: false, error: "Missing or invalid query parameter: orderId (Webshipper order id)" },
      { status: 400 }
    );
  }

  const result = await getReturnLabelPdfForOrder(orderId);
  if (!result.ok) {
    return NextResponse.json(
      { ok: false, error: result.error },
      { status: result.error.includes("failed") || result.error.includes("not yet") ? 502 : 400 }
    );
  }
  return NextResponse.json({ ok: true, pdfBase64: result.pdfBase64 });
}