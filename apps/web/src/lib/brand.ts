"use client";

import { useMutation } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";
import type { Brand, Locale } from "./api/types";
import { upload } from "./api/upload";
import { rememberBrand, rememberedBrand } from "./brandMemory";
import { mediaUrl } from "./images/productImage";
import { authed, useSession } from "./session";

// The business name and logo the app shows (Phase 6 follow-up, Ramin 2026-11-10). They are the bakery's, set by the admin on the live
// site (Admin > System > Brand) and read from GET /me; the repository only knows the app's own name and mark, which show until the admin
// sets them. Signed out (the sign-in screen), the brand this device last saw stands in (lib/brandMemory.ts).

export interface BrandView {
  /** the name in the page's language, or the app's own */
  name: string;
  /** the logo's URL, or null for the app's own mark */
  logo: string | null;
  /** the bakery's own brand is set */
  custom: boolean;
}

/** The brand to draw: /me's when signed in, else the one this device last saw, else the app's own. */
export function useBrand(): BrandView {
  const t = useTranslations("app");
  const locale = useLocale() as Locale;
  const fromMe = useSession((s) => s.me?.brand);
  // read after the first paint, so the server-rendered page and the first client render agree
  const [remembered, setRemembered] = useState<Brand | null>(null);
  useEffect(() => {
    if (!fromMe) setRemembered(rememberedBrand());
  }, [fromMe]);
  const brand = fromMe ?? remembered;
  const name = brand ? (locale === "am" ? brand.name_am : brand.name_en) : null;
  return { name: name ?? t("name"), logo: brand?.logo ? mediaUrl(brand.logo.url) : null, custom: Boolean(brand && !brand.is_default) };
}

/** The page's title ends in the app's name (layout metadata); with the bakery's name set, it ends in that one instead. */
export function useBrandTitle(): void {
  const t = useTranslations("app");
  const { name, custom } = useBrand();
  useEffect(() => {
    if (!custom) return;
    const own = t("name");
    const rename = () => {
      const title = document.title;
      if (title === own) document.title = name;
      else if (title.endsWith(` · ${own}`)) document.title = `${title.slice(0, -own.length)}${name}`;
    };
    rename();
    const head = document.querySelector("head");
    if (!head) return;
    const observer = new MutationObserver(rename);
    observer.observe(head, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
  }, [custom, name, t]);
}

/** Every brand write answers the brand; the session's /me takes it at once, and this device remembers it. */
function adopt(brand: Brand): void {
  const { me } = useSession.getState();
  if (me) useSession.setState({ me: { ...me, brand } });
  rememberBrand(brand);
}

/** PUT /brand: the name pair, both or neither (neither: the app's own name again). */
export function useSaveBrandName() {
  return useMutation({
    mutationFn: ({ nameEn, nameAm, idempotencyKey }: { nameEn: string; nameAm: string; idempotencyKey: string }) =>
      authed<{ data: Brand }>("/brand", { method: "PUT", body: { name_en: nameEn.trim() || null, name_am: nameAm.trim() || null }, idempotencyKey }),
    onSuccess: (answer) => adopt(answer.data),
  });
}

/** POST /brand/logo (multipart `logo`): the server keeps it whole on a white square. */
export function useUploadBrandLogo() {
  return useMutation({
    mutationFn: ({ file, idempotencyKey }: { file: File; idempotencyKey: string }) => {
      const form = new FormData();
      form.append("logo", file);
      return upload<{ data: Brand }>("/brand/logo", { form, idempotencyKey });
    },
    onSuccess: (answer) => adopt(answer.data),
  });
}

/** DELETE /brand/logo: the app's own mark again. */
export function useRemoveBrandLogo() {
  return useMutation({
    mutationFn: ({ idempotencyKey }: { idempotencyKey: string }) => authed<{ data: Brand }>("/brand/logo", { method: "DELETE", idempotencyKey }),
    onSuccess: (answer) => adopt(answer.data),
  });
}
