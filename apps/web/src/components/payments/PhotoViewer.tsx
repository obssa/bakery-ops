"use client";

import { ImageOff, X } from "lucide-react";
import { useTranslations } from "next-intl";
import { useCallback, useEffect, useRef, useState } from "react";
import { signedFileUrl } from "@/lib/api/adminPayments";
import { useErrorText } from "@/lib/errors";
import { ICON_STROKE } from "../icons";
import { Alert, Button, IconButton, Modal } from "../ui";
import { focusRing } from "../styles";

// A payment's receipt photo (step 4.5c). The picture is only ever read through a signed URL that lives five minutes (ADR 0015): the list's
// URL may be close to its end, so the viewer always asks GET /files/{file}/url for a fresh one when it opens, and once more when the picture
// fails to load (an URL that expired while the viewer stayed open). A thumbnail starts from the list's URL and re-signs the same way.
// Step 6.4c: the viewer's title row closes with an X ("Close"), the picture and the thumbs on the admin radii.

/** How many fresh URLs one picture may ask for before it says it cannot be loaded (the first, then one after an error). */
const MAX_SIGNS = 2;

function useSignedUrl(fileId: number, initial: string | null) {
  const [url, setUrl] = useState<string | null>(initial);
  const [error, setError] = useState<unknown>(null);
  const [failed, setFailed] = useState(false);
  const signs = useRef(0);
  const alive = useRef(true);

  const sign = useCallback(async () => {
    if (signs.current >= MAX_SIGNS) {
      setFailed(true);
      return;
    }
    signs.current += 1;
    try {
      const fresh = await signedFileUrl(fileId);
      if (alive.current) setUrl(fresh.url);
    } catch (cause) {
      if (alive.current) {
        setError(cause);
        setFailed(true);
      }
    }
  }, [fileId]);

  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  const retry = useCallback(() => {
    signs.current = 0;
    setFailed(false);
    setError(null);
    setUrl(null);
    void sign();
  }, [sign]);

  return { url, error, failed, sign, retry };
}

/** The photo over the screen, from a fresh signed URL. */
export function PhotoViewer({ paymentId, fileId, onClose }: { paymentId: number; fileId: number; onClose: () => void }) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  const { url, error, failed, sign, retry } = useSignedUrl(fileId, null);

  useEffect(() => {
    void sign();
  }, [sign]);

  const title = t("viewer.title", { id: paymentId });
  return (
    <Modal label={title} size="lg" onClose={onClose}>
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-lg font-semibold text-neutral-900">{title}</h2>
        <IconButton label={t("close")} size="md" onClick={onClose} className="-mr-2 -mt-2">
          <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      </div>
      {failed ? (
        <div className="space-y-3">
          <Alert>{error ? errorText.message(error) : t("viewer.failed")}</Alert>
          <Button variant="secondary" size="md" onClick={retry}>
            {t("viewer.retry")}
          </Button>
        </div>
      ) : url ? (
        // a signed URL of the API's file store: next/image has nothing to optimise in a static export
        // eslint-disable-next-line @next/next/no-img-element
        <img src={url} alt={title} onError={() => void sign()} className="mx-auto max-h-[70vh] w-auto rounded-xl border border-neutral-200 object-contain" />
      ) : (
        <p role="status" className="py-10 text-center text-[15px] text-neutral-600">
          {t("viewer.loading")}
        </p>
      )}
    </Modal>
  );
}

/** A small picture of the photo that opens the viewer; it starts from the list's signed URL and asks for a fresh one if that fails. */
export function PhotoThumb({ label, fileId, url, onOpen, large = false }: { label: string; fileId: number; url: string | null; onOpen: () => void; large?: boolean }) {
  const signed = useSignedUrl(fileId, url);
  const { sign } = signed;

  useEffect(() => {
    if (url === null) void sign();
  }, [url, sign]);

  return (
    <button
      type="button"
      aria-label={label}
      onClick={onOpen}
      className={`inline-flex ${large ? "size-40 rounded-2xl" : "size-12 rounded-[10px]"} shrink-0 items-center justify-center overflow-hidden border border-neutral-200 bg-neutral-100 text-neutral-400 hover:border-neutral-400 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`}
    >
      {signed.url && !signed.failed ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={signed.url} alt="" onError={() => void sign()} className="h-full w-full object-cover" />
      ) : (
        <ImageOff className={large ? "size-8" : "size-5"} strokeWidth={ICON_STROKE} aria-hidden="true" />
      )}
    </button>
  );
}
