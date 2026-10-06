/**
 * Builds database/seeders/data/johndorf-projects.json for JohndorfProjectsSeeder.
 * Facts: Johndorf's own project pages (lib/johndorf/projects.ts, read 2026-10-05) and
 * company.ts (places, status). Prices: published listings / press, cited per unit.
 */
import fs from "node:fs"
import { PROJECT_DETAILS } from "/Users/juliecor/Documents/jvonline/jvonlinefrontend/lib/johndorf/projects.ts"
import { PROJECTS } from "/Users/juliecor/Documents/jvonline/jvonlinefrontend/lib/johndorf/company.ts"

type Price = { price: number | null; status?: "available" | "sold"; source: string }
// Per project slug → per house type (index in Johndorf's list) → published price with its source.
const PRICES: Record<string, Record<number, Price>> = {
  montierra: { 0: { price: 2_800_000, source: "Listed at ₱2,800,000 total contract price, ₱15,000 reservation, equity over 18–24 months (lionunion.com); other listings ₱2.4M–₱3.0M (dotproperty.com.ph)." } },
  plumera: {
    0: { price: 2_600_000, source: "Studio units listed from ₱2.6M to ₱3.1M depending on building (myhouse.ph, 2026 update)." },
    1: { price: 4_400_000, source: "1-bedroom units listed from ₱4.4M to ₱5.2M depending on building (myhouse.ph, 2026 update)." },
  },
  "navona-court": { 0: { price: 3_775_000, source: "Listed at ₱3,775,000 total contract price (cdorealty.com); other listings ₱3.53M–₱3.60M (onepropertee.com)." } },
  "villa-castena": {
    0: { price: 3_900_000, source: "Listed at ₱3,900,000, lot 80 sqm (loveprimeestate.com)." },
    1: { price: null, source: "Listings from ₱1.5M (lionunion.com); no exact price published." },
  },
  "tierranava-lumbia": { 0: { price: 1_900_000, source: "Listed at ₱1,900,000 (highlandsrealtyph.com); a 2021 promo quoted ₱1.4M TCP with ₱10,000 reservation." } },
  "mimosa-minglanilla": { 0: { price: 3_434_000, source: "Listed at ₱3,434,000 (inner unit) and ₱4,518,080 (filipinohomes.com / cebuhomesfinder.com); launched at ₱3.8M (Philstar, 2018)." } },
  "coral-village": { 0: { price: 3_068_800, source: "Middle unit about ₱3,068,800, end unit about ₱3,589,600 (ceburealhomes.com)." } },
  "evissa-lapu-lapu": { 0: { price: 1_850_000, status: "sold", source: "Sold out. Initial units were ₱1.8M–₱1.86M (irealtee.com); 391 townhouses launched at ₱1.4M–₱1.7M (SunStar)." } },
  "evissa-davao": { 0: { price: 1_400_000, source: "391 two-storey townhouses launched at ₱1.4M–₱1.7M (SunStar); ₱1.4M selling price (realestateindavao.com)." } },
  "navona-davao": { 0: { price: 2_300_000, source: "Listed from ₱2,300,000 (lionunion.com)." } },
  "astana-davao": { 0: { price: 3_300_000, source: "Listed ₱3.3M–₱4.5M (davaohomes.ph)." } },
}
const COORDS: Record<string, [number, number]> = {"astana-davao": [7.074495, 125.569115], "coral-village": [10.267427, 123.97309], "evissa-lapu-lapu": [10.2819414, 123.939206], "mimosa-cebu": [10.30341, 123.8816254], "montierra": [8.4292355, 124.6203083], "navona-court": [8.3987303, 124.6102897], "navona-davao": [7.074685, 125.569736], "navona-lumbia": [8.3973495, 124.6099222], "pich-4b": [8.489345, 124.570182], "plumera": [10.2959595, 123.97544], "villa-castena": [8.299862, 124.260761]}
const NO_PRICE = "No price published online; ask Johndorf for the current price list."

const out = []
for (const [slug, d] of Object.entries(PROJECT_DETAILS)) {
  const meta = PROJECTS.find((p) => p.slug === slug)
  if (!meta) continue
  const updates = d.updates ? `Johndorf published ${d.updates.count} construction update${d.updates.count === 1 ? "" : "s"} (${d.updates.first === d.updates.last ? d.updates.first : d.updates.first + " to " + d.updates.last}).` : ""
  const types = d.units.map((u) => u.type).join(", ")
  const description = [
    `${meta.name} by Johndorf Ventures Corporation in ${meta.place}.`,
    `House models: ${types}.`,
    d.amenities.length ? `Amenities: ${d.amenities.join(", ")}.` : "",
    updates,
    `Details from Johndorf's project page, read October 2025.`,
  ].filter(Boolean).join(" ")
  const units = d.units.map((u, i) => {
    const pr = PRICES[slug]?.[i]
    const spec = [u.floorArea ? `${u.floorArea} sqm usable floor area` : null, u.bedrooms !== "—" ? `${u.bedrooms} bedroom${u.bedrooms === "1" ? "" : "s"}` : null, u.baths !== "—" ? `${u.baths} T&B` : null, u.floors !== "—" ? `${u.floors} floor${u.floors === "1" ? "" : "s"}` : null, u.parking !== "—" ? `${u.parking} parking` : null].filter(Boolean).join(", ")
    return {
      name: u.type,
      unit_type: u.type,
      category: "Residential",
      floor: null,
      area_sqm: u.floorArea,
      price: pr?.price ?? null,
      status: pr?.status ?? "available",
      notes: `${spec}. ${pr ? pr.source : NO_PRICE}`.trim(),
    }
  })
  out.push({
    name: meta.name,
    slug,
    location: meta.place,
    lat: COORDS[slug]?.[0] ?? null,
    lng: COORDS[slug]?.[1] ?? null,
    description,
    cover_path: meta.image,
    fee_notes: "Prices shown come from published listings and press and may have changed; Johndorf confirms the current price on reservation. Financing through the Pag-IBIG Fund or accredited banks; a 5% discount applied to equity paid in spot cash in past Johndorf promotions (SunStar). Transfer and registration fees are for the buyer's account unless agreed otherwise.",
    status: meta.status === "Completed" || slug === "evissa-lapu-lapu" ? "archived" : "active",
    units,
    official_url: d.officialUrl,
  })
}
fs.writeFileSync("/Users/juliecor/Documents/jvonline/jvonlinebackend/database/seeders/data/johndorf-projects.json", JSON.stringify(out, null, 2))
console.log(out.length, "projects;", out.reduce((n, p) => n + p.units.length, 0), "units;", out.reduce((n, p) => n + p.units.filter((u: any) => u.price !== null).length, 0), "priced")
