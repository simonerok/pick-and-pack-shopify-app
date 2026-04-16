import { NextResponse } from "next/server";
import {
  isBCConfigured,
  getBCAccessToken,
  resolveBCCompany,
  createBCSalesOrderLine,
} from "../../../../../../lib/business-central";
import { isProduction } from "../../../../../../lib/app-status";

type RouteContext = { params: Promise<{ bcOrderId: string }> };

/**
 * POST /api/business-central/orders/[bcOrderId]/lines
 * Creates a Comment sales order line on the given BC sales order.
 * Body: { description: string }
 * Disabled when VITE_APP_STATUS is not "Production".
 */
export async function POST(request: Request, context: RouteContext) {
  if (!isProduction()) {
    return NextResponse.json(
      { ok: false, error: "App is in Test mode; Business Central changes are disabled. Set VITE_APP_STATUS=Production to enable." },
      { status: 403 }
    );
  }

  if (!isBCConfigured()) {
    return NextResponse.json(
      { ok: false, error: "Business Central is not configured" },
      { status: 503 }
    );
  }

  const { bcOrderId } = await context.params;
  if (!bcOrderId?.trim()) {
    return NextResponse.json(
      { ok: false, error: "Missing sales order ID" },
      { status: 400 }
    );
  }

  let body: { description?: string };
  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { ok: false, error: "Invalid JSON body" },
      { status: 400 }
    );
  }

  const description =
    typeof body.description === "string" ? body.description.trim() : "";
  if (!description) {
    return NextResponse.json(
      { ok: false, error: "description is required" },
      { status: 400 }
    );
  }

  try {
    const token = await getBCAccessToken();
    const resolved = await resolveBCCompany(token);
    if (!resolved) {
      return NextResponse.json(
        { ok: false, error: "No company found in Business Central" },
        { status: 500 }
      );
    }

    await createBCSalesOrderLine(token, resolved.companyId, bcOrderId.trim(), {
      lineType: "Comment",
      description,
    });

    return NextResponse.json({ ok: true });
  } catch (err) {
    const message = err instanceof Error ? err.message : "Failed to create sales order line";
    return NextResponse.json(
      { ok: false, error: message },
      { status: 500 }
    );
  }
}