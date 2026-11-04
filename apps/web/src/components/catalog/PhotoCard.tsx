"use client";

import { Check, CircleAlert, ImageIcon, TriangleAlert, Upload } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useCallback, useEffect, useId, useRef, useState, type DragEvent, type ReactNode } from "react";
import { useRemoveProductPhoto, useUploadProductPhoto } from "@/lib/api/catalog";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import type { Item, Locale, MenuItem, ProductImageDetail, ProductImageView } from "@/lib/api/types";
import { dayText } from "@/lib/dates";
import { useErrorText } from "@/lib/errors";
import { downscaleSized, PRODUCT_PHOTO } from "@/lib/images/downscale";
import { cropFields, initialView, qualityOf, sourceRect, type CropRect, type FrameView, type PhotoQuality } from "@/lib/images/frame";
import { addisDate } from "@/lib/shiftsAdmin";
import { useDateFormat } from "@/lib/useDates";
import { ProductCard, type ProductCardItem } from "../pad/ProductCard";
import { Button, Modal, ProductImage } from "../ui";
import { FramedImage, PhotoFramer, type PreparedPhoto } from "./PhotoFramer";

// The product page's Photo card (step 6.4b; docs/design/fresh-counter.md 4.3 "Photo card"; the ProductPhoto and PhotoStates boards), the
// sticky right column of the page. Its states, as the boards draw them:
//   - no photo: a 4:3 dropzone (dashed neutral-300 on neutral-50; "Drop a photo here", "or browse your files"), the rules line and the
//     placeholder the pad shows meanwhile;
//   - a file dragged over the card: the dropzone in brand-50 with a solid brand border, "Drop to upload", "Let go to add it";
//   - the framing step (PhotoFramer): the photo in the fixed 4:3 frame, the zoom, the quality line (sharp from 1200 x 900, soft from
//     800 x 600, too small below: "Use this photo" is off), "How it will look" (the real pad card with its stock chip and the 48 px
//     order-line thumb, both drawn from the frame as it moves), "Use this photo", "Choose another" and "Cancel";
//   - uploading: the framed photo dimmed under a white panel, "Uploading and making sizes" with the percent and a progress bar; the pad
//     keeps the old photo until the answer;
//   - with a photo: the large rendition, "Added by <name> · <date> · W × H", "Replace" and "Remove" (confirmed in a small dialog);
//   - a refusal: the server's message (or the card's own, for a file it cannot take) in a red-50 box under the card.
// The file is checked (JPEG, PNG or WebP), decoded and shrunk in the browser (2400 px, JPEG 0.9: lib/images/downscale.ts), framed, and sent
// with the crop in that file's pixels (lib/images/frame.ts) through lib/api/upload.ts, an XMLHttpRequest for the progress. One
// Idempotency-Key and one client_uuid per photo and crop, so a retry after a failure is the same write. Online only: admin screens never
// queue a write in the outbox. Only catalog.manage changes the photo; anyone else sees it.

const ACCEPTED = ["image/jpeg", "image/png", "image/webp"] as const;
const ACCEPT = ACCEPTED.join(",");
const ACCEPTED_NAME = /\.(jpe?g|png|webp)$/i;
/** The share of the bar the upload's bytes fill; the rest is the server making the three sizes. */
const SENT_SHARE = 90;

const CARD = "flex flex-col gap-4 rounded-[18px] border border-neutral-200 bg-white p-5";

type Stage =
  | { kind: "idle" }
  | { kind: "opening" }
  | { kind: "framing"; photo: PreparedPhoto; view: FrameView }
  | { kind: "uploading"; photo: PreparedPhoto; view: FrameView; sent: number };

function acceptable(file: File): boolean {
  if ((ACCEPTED as readonly string[]).includes(file.type)) return true;
  // some systems give no type for a file they do not know; the name decides then, and the browser's decoding after it
  return file.type === "" && ACCEPTED_NAME.test(file.name);
}

function hasFiles(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes("Files");
}

function isDetail(image: ProductImageView | ProductImageDetail | null | undefined): image is ProductImageDetail {
  return !!image && "id" in image;
}

const PIXELS = new Intl.NumberFormat("en-US");

export interface PhotoCardProps {
  product: Item;
  /** the product as the pad's menu has it now (its price and stock), for the live preview; null when the menu does not list it */
  menuEntry: MenuItem | null;
  /** the current price, for the preview of a product the menu does not list */
  price: { price: string; currency: string } | null;
  /** its category's name in either language, for the placeholder's icon */
  category: string | null;
  canManage: boolean;
  className?: string;
}

export function PhotoCard({ product, menuEntry, price, category, canManage, className = "" }: PhotoCardProps) {
  const t = useTranslations("photo");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const fmt = useDateFormat();
  const titleId = useId();
  const card = useRef<HTMLElement>(null);
  const picker = useRef<HTMLInputElement>(null);
  const upload = useUploadProductPhoto(product.id);
  const remove = useRemoveProductPhoto(product.id);
  const uploadKey = useIdempotencyKey();
  const removeKey = useIdempotencyKey();
  const clientUuid = useRef<{ fingerprint: string; id: string } | null>(null);

  const [stage, setStage] = useState<Stage>({ kind: "idle" });
  const [refusal, setRefusal] = useState<string | null>(null);
  const [said, setSaid] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  // how deep a drag of files is over the card (enter and leave fire for every child it crosses)
  const [depth, setDepth] = useState(0);

  const name = locale === "am" ? product.name_am : product.name_en;
  const image = product.image ?? null;
  const busy = stage.kind === "uploading" || stage.kind === "opening" || remove.isPending;
  const dragging = canManage && depth > 0 && stage.kind !== "uploading";

  // the object URL of the photo on screen goes when the photo does
  const shownUrl = stage.kind === "framing" || stage.kind === "uploading" ? stage.photo.url : null;
  useEffect(() => {
    if (!shownUrl) return;
    return () => URL.revokeObjectURL(shownUrl);
  }, [shownUrl]);

  // "Add photo" in the catalog list opens the page at this card (?item=<id>#photo, step 6.4a): the page draws it after its read
  useEffect(() => {
    if (window.location.hash === "#photo") card.current?.scrollIntoView({ block: "start" });
  }, []);

  // a file dropped beside the card would open in the tab, and the page would be lost
  useEffect(() => {
    if (!canManage) return;
    const keep = (event: globalThis.DragEvent) => {
      if (Array.from(event.dataTransfer?.types ?? []).includes("Files")) event.preventDefault();
    };
    window.addEventListener("dragover", keep);
    window.addEventListener("drop", keep);
    return () => {
      window.removeEventListener("dragover", keep);
      window.removeEventListener("drop", keep);
    };
  }, [canManage]);

  const setView = useCallback((update: (view: FrameView) => FrameView) => {
    setStage((current) => (current.kind === "framing" ? { ...current, view: update(current.view) } : current));
  }, []);

  async function take(file: File | null | undefined) {
    if (!file || !canManage || stage.kind === "uploading") return;
    setRefusal(null);
    setSaid(null);
    if (!acceptable(file)) {
      setRefusal(t("refused_type"));
      return;
    }
    setStage({ kind: "opening" });
    const shrunk = await downscaleSized(file, PRODUCT_PHOTO);
    if (!shrunk) {
      // back to the card at rest (a photo being framed before has let its URL go)
      setStage({ kind: "idle" });
      setRefusal(t("refused_unreadable"));
      return;
    }
    const photo: PreparedPhoto = { ...shrunk, url: URL.createObjectURL(shrunk.blob) };
    setStage({ kind: "framing", photo, view: initialView(photo) });
  }

  function save(photo: PreparedPhoto, view: FrameView) {
    const rect = sourceRect(photo, view);
    if (qualityOf(rect) === "too_small" || upload.isPending) return;
    const fingerprint = JSON.stringify({ photo: photo.url, rect });
    if (clientUuid.current?.fingerprint !== fingerprint) clientUuid.current = { fingerprint, id: uuid() };
    const form = new FormData();
    form.append("photo", photo.blob, "photo.jpg");
    for (const [field, value] of Object.entries(cropFields(rect))) form.append(field, value);
    form.append("client_uuid", clientUuid.current.id);
    setRefusal(null);
    setStage({ kind: "uploading", photo, view, sent: 0 });
    upload.mutate(
      {
        form,
        idempotencyKey: uploadKey.keyFor(fingerprint),
        onProgress: (sent) => setStage((current) => (current.kind === "uploading" ? { ...current, sent } : current)),
      },
      {
        onSuccess: () => {
          uploadKey.done();
          clientUuid.current = null;
          setStage({ kind: "idle" });
          setSaid(t("saved"));
        },
        onError: (error) => {
          // back to the frame as it was, with the server's words under the card
          setStage({ kind: "framing", photo, view });
          setRefusal(errorText.message(error));
        },
      },
    );
  }

  function confirmRemove() {
    if (!image) return;
    remove.mutate(
      { idempotencyKey: removeKey.keyFor({ item: product.id, image: image.large }) },
      {
        onSuccess: () => {
          removeKey.done();
          setConfirming(false);
          setSaid(t("removed"));
        },
        onError: (error) => {
          setConfirming(false);
          setRefusal(errorText.message(error));
        },
      },
    );
  }

  function choose() {
    picker.current?.click();
  }

  const meta = metaOf();
  function metaOf(): { text: string; brand?: boolean } {
    if (dragging) return { text: t("meta_drag"), brand: true };
    if (stage.kind === "opening") return { text: t("meta_opening") };
    if (stage.kind === "framing") return { text: t("meta_framing") };
    if (stage.kind === "uploading") return { text: t("meta_saving") };
    if (image) return { text: product.is_active ? t("meta_on_menu") : t("meta_off_menu") };
    return { text: t("meta_empty") };
  }

  let body: ReactNode;
  if (stage.kind === "uploading") {
    const percent = Math.round(stage.sent * SENT_SHARE);
    body = (
      <>
        <div className="relative aspect-[4/3] overflow-hidden rounded-[14px] bg-neutral-100">
          <FramedImage photo={stage.photo} view={stage.view} className="opacity-60" />
          <div className="absolute inset-x-3.5 bottom-3.5 flex flex-col gap-2 rounded-xl bg-white p-3">
            <span className="flex justify-between gap-3 text-[13px] font-semibold text-neutral-900">
              <span>{t("uploading")}</span>
              <span className="tabular-nums">{t("percent", { percent })}</span>
            </span>
            <div role="progressbar" aria-label={t("upload_label")} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="h-2 overflow-hidden rounded-full bg-neutral-100">
              <div className="h-2 rounded-full bg-brand-600 motion-safe:transition-[width] motion-safe:duration-150" style={{ width: `${percent}%` }} />
            </div>
          </div>
        </div>
        <p className="text-[13px] leading-[1.45] text-neutral-600">{t("uploading_note")}</p>
      </>
    );
  } else if (dragging) {
    body = (
      <>
        <Dropzone dragging onFile={take} />
        <p className="text-[13px] leading-[1.45] text-neutral-600">{t("rules_drag")}</p>
      </>
    );
  } else if (stage.kind === "framing") {
    const { photo, view } = stage;
    const rect = sourceRect(photo, view);
    const quality = qualityOf(rect);
    body = (
      <Framing
        photo={photo}
        view={view}
        rect={rect}
        quality={quality}
        name={name}
        locale={locale}
        category={category}
        item={previewItem(product, menuEntry, price)}
        onView={setView}
        onUse={() => save(photo, view)}
        onChoose={choose}
        onCancel={() => {
          setStage({ kind: "idle" });
          setRefusal(null);
        }}
      />
    );
  } else if (image) {
    body = (
      <>
        <ProductImage image={image} name={name} category={category} size="large" className="rounded-[14px]" />
        <p className="text-[13px] text-neutral-600">{addedLine(image)}</p>
        {canManage ? (
          <div className="flex gap-2.5">
            <Button variant="secondary" size="md" className="grow" disabled={busy} onClick={choose}>
              {t("replace")}
            </Button>
            <Button variant="danger" size="md" className="grow" disabled={busy} onClick={() => setConfirming(true)}>
              {t("remove")}
            </Button>
          </div>
        ) : null}
      </>
    );
  } else if (canManage) {
    body = (
      <>
        <Dropzone dragging={false} disabled={busy} onFile={take} />
        <p className="text-[13px] leading-[1.45] text-neutral-600">{t("rules_empty")}</p>
        <PlaceholderRow name={name} category={category} />
      </>
    );
  } else {
    body = (
      <>
        <ProductImage image={null} name={name} category={category} size="large" className="rounded-[14px]" />
        <p className="text-[13px] text-neutral-600">{t("read_only")}</p>
      </>
    );
  }

  function addedLine(photo: ProductImageView | ProductImageDetail): string {
    const size = { width: photo.width, height: photo.height };
    if (!isDetail(photo) || !photo.uploaded_at) return `${photo.width} × ${photo.height}`;
    const at = new Date(photo.uploaded_at);
    const date = Number.isNaN(at.getTime()) ? photo.uploaded_at : dayText(addisDate(at), fmt, { withYear: true });
    if (photo.origin === "demo") return t("added_demo", { date, ...size });
    const by = photo.uploaded_by?.name;
    return by ? t("added_by", { name: by, date, ...size }) : t("added_on", { date, ...size });
  }

  return (
    <section
      ref={card}
      id="photo"
      aria-labelledby={titleId}
      className={`${CARD} scroll-mt-6 ${className}`}
      onDragEnter={(event) => {
        if (!canManage || !hasFiles(event)) return;
        event.preventDefault();
        setDepth((d) => d + 1);
      }}
      onDragOver={(event) => {
        if (!canManage || !hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = stage.kind === "uploading" ? "none" : "copy";
      }}
      onDragLeave={(event) => {
        if (!canManage || !hasFiles(event)) return;
        setDepth((d) => Math.max(0, d - 1));
      }}
      onDrop={(event) => {
        if (!canManage) return;
        event.preventDefault();
        setDepth(0);
        void take(event.dataTransfer.files[0]);
      }}
    >
      <div className="flex items-baseline justify-between gap-3">
        <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
          {t("title")}
        </h2>
        <span className={`text-right text-[13px] ${meta.brand ? "font-semibold text-brand-700" : "text-neutral-600"}`}>{meta.text}</span>
      </div>
      {body}
      {refusal ? (
        <div role="alert" className="flex items-start gap-2.5 rounded-xl bg-red-50 px-3 py-2.5 text-[13px] leading-[1.45] text-red-800">
          <CircleAlert className="size-[18px] shrink-0" strokeWidth={2} aria-hidden="true" />
          <span>{refusal}</span>
        </div>
      ) : null}
      <p role="status" aria-live="polite" className="sr-only">
        {said}
      </p>
      {canManage ? (
        <input
          ref={picker}
          type="file"
          accept={ACCEPT}
          tabIndex={-1}
          aria-hidden="true"
          className="hidden"
          onChange={(event) => {
            void take(event.target.files?.[0]);
            event.target.value = "";
          }}
        />
      ) : null}
      {confirming ? (
        <Modal label={t("remove_title")} onClose={() => (remove.isPending ? undefined : setConfirming(false))}>
          <h2 className="text-lg font-semibold text-neutral-900">{t("remove_title")}</h2>
          <p className="text-base text-neutral-700">{t("remove_body", { name })}</p>
          <div className="flex flex-wrap justify-end gap-2.5">
            <Button variant="secondary" size="md" autoFocus disabled={remove.isPending} onClick={() => setConfirming(false)}>
              {t("remove_keep")}
            </Button>
            <Button variant="danger" size="md" loading={remove.isPending} onClick={confirmRemove}>
              {t("remove_confirm")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </section>
  );
}

/** The product as the preview's pad card draws it: the menu's entry (its price and stock), else its own names and price. */
function previewItem(product: Item, entry: MenuItem | null, price: { price: string; currency: string } | null): ProductCardItem {
  if (entry) return { ...entry, image: null };
  return {
    item_id: product.id,
    name_en: product.name_en,
    name_am: product.name_am,
    price: price?.price ?? "0.00",
    currency: price?.currency ?? "ETB",
    available: "1",
    low: false,
    is_new: product.is_new,
    image: null,
  };
}

/**
 * The 4:3 box a file is dropped on or chosen through (a label around the file input, so a tap opens the browser's chooser and the keyboard
 * reaches it), in its two looks: at rest, and with a file dragged over the card. The input stays the same element in both.
 */
function Dropzone({ dragging, disabled = false, onFile }: { dragging: boolean; disabled?: boolean; onFile: (file: File | undefined) => void }) {
  const t = useTranslations("photo");
  return (
    <label
      data-testid="photo-dropzone"
      className={`relative flex aspect-[4/3] cursor-pointer flex-col items-center justify-center gap-2.5 rounded-[14px] border-2 p-4 text-center has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-600/35 has-[:focus-visible]:ring-offset-1 ${
        dragging ? "border-solid border-brand-600 bg-brand-50" : "border-dashed border-neutral-300 bg-neutral-50 hover:border-neutral-400"
      }`}
    >
      <span aria-hidden="true" className={`inline-flex size-12 items-center justify-center rounded-full ${dragging ? "bg-white text-brand-600" : "bg-neutral-100 text-neutral-600"}`}>
        {dragging ? <Upload className="size-6" strokeWidth={2} aria-hidden="true" /> : <ImageIcon className="size-6" strokeWidth={1.8} aria-hidden="true" />}
      </span>
      {dragging ? (
        <>
          <span className="text-[15px] font-semibold text-brand-700">{t("drop_now")}</span>{" "}
          <span className="text-sm text-brand-700">{t("drop_types")}</span>
        </>
      ) : (
        <>
          <span className="text-[15px] font-semibold text-neutral-900">{t("drop")}</span>{" "}
          <span className="text-sm text-neutral-600">{t.rich("browse", { b: (chunks) => <span className="font-semibold text-brand-600">{chunks}</span> })}</span>
        </>
      )}
      <input
        type="file"
        accept={ACCEPT}
        disabled={disabled}
        className="sr-only"
        onChange={(event) => {
          onFile(event.target.files?.[0]);
          event.target.value = "";
        }}
      />
    </label>
  );
}

/** What the pad shows while the product has no photo: its category's placeholder, 64 x 48, beside the name. */
function PlaceholderRow({ name, category }: { name: string; category: string | null }) {
  const t = useTranslations("photo");
  return (
    <div className="flex items-center gap-2.5 rounded-xl border border-neutral-200 p-2.5">
      <ProductImage image={null} name={name} category={category} size="thumb" className="w-16 shrink-0 rounded-[10px]" />
      <span className="flex min-w-0 flex-col">
        <span className="truncate text-sm font-semibold text-neutral-900">{name}</span>
        <span className="text-[13px] text-neutral-600">{t("placeholder_note")}</span>
      </span>
    </div>
  );
}

const QUALITY_LOOK: Record<PhotoQuality, { tone: string; icon: typeof Check; key: "quality_sharp" | "quality_soft" | "quality_small" }> = {
  sharp: { tone: "bg-brand-50 text-brand-700", icon: Check, key: "quality_sharp" },
  soft: { tone: "bg-amber-50 text-amber-800", icon: TriangleAlert, key: "quality_soft" },
  too_small: { tone: "bg-red-50 text-red-700", icon: CircleAlert, key: "quality_small" },
};

function Framing({
  photo,
  view,
  rect,
  quality,
  name,
  locale,
  category,
  item,
  onView,
  onUse,
  onChoose,
  onCancel,
}: {
  photo: PreparedPhoto;
  view: FrameView;
  rect: CropRect;
  quality: PhotoQuality;
  name: string;
  locale: Locale;
  category: string | null;
  item: ProductCardItem;
  onView: (update: (view: FrameView) => FrameView) => void;
  onUse: () => void;
  onChoose: () => void;
  onCancel: () => void;
}) {
  const t = useTranslations("photo");
  const qualityId = useId();
  const previewId = useId();
  const look = QUALITY_LOOK[quality];
  const Icon = look.icon;
  return (
    <>
      <PhotoFramer photo={photo} view={view} name={name} onView={onView} />
      <p id={qualityId} className={`flex items-start gap-2.5 rounded-xl px-3 py-2.5 text-sm font-semibold ${look.tone}`}>
        <Icon className="mt-px size-[18px] shrink-0" strokeWidth={2.2} aria-hidden="true" />
        <span>{t(look.key, { width: PIXELS.format(rect.width), height: PIXELS.format(rect.height) })}</span>
      </p>
      <section aria-labelledby={previewId} className="flex flex-col gap-2.5 border-t border-neutral-100 pt-1">
        <h3 id={previewId} className="pt-3 text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">
          {t("preview")}
        </h3>
        <div className="flex flex-wrap items-start gap-4">
          <div className="w-[182px] shrink-0">
            <ProductCard item={item} locale={locale} category={category} preview photo={<FramedImage photo={photo} view={view} />} />
          </div>
          <div className="flex min-w-0 flex-[1_1_160px] flex-col gap-2.5">
            <span className="text-[13px] text-neutral-600">{t("preview_pad")}</span>
            <div className="flex items-center gap-2.5 rounded-xl border border-neutral-200 p-2.5">
              <span className="relative block h-9 w-12 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
                <FramedImage photo={photo} view={view} />
              </span>
              <span className="min-w-0 grow truncate text-sm font-semibold text-neutral-900">{name}</span>
              <span className="shrink-0 text-sm font-semibold tabular-nums text-neutral-900">{t("line_qty")}</span>
            </div>
            <span className="text-[13px] text-neutral-600">{t("preview_line")}</span>
          </div>
        </div>
      </section>
      <div className="flex flex-wrap gap-2.5">
        <Button className="flex-[1_1_140px]" disabled={quality === "too_small"} aria-describedby={qualityId} onClick={onUse}>
          {t("use")}
        </Button>
        <Button variant="secondary" onClick={onChoose}>
          {t("choose_another")}
        </Button>
        <Button variant="ghost" onClick={onCancel}>
          {t("cancel")}
        </Button>
      </div>
      <p className="text-[13px] text-neutral-600">{t("rules_framing")}</p>
    </>
  );
}
