"use client";

import { ImageUp, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useId, useState, type FormEvent } from "react";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { useRemoveBrandLogo, useSaveBrandName, useUploadBrandLogo } from "@/lib/brand";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { CARD } from "../admin/kit";
import { AppMark } from "../AppShell";
import { Alert, Button, Field, Status, TextInput } from "../ui";

// Admin > System > Brand (Phase 6 follow-up, Ramin 2026-11-10): the bakery's name, in English and Amharic, and its logo, which the sidebar,
// the station bar, the sign-in screen and the browser's title show instead of the app's own. Set here on the live site, so the public
// repository never holds them. For branding.manage (the admin); nobody else sees the card. The logo is kept whole on a white square by the
// server (JPEG, PNG or WebP, at most 5 MB); the preview is the mark as the sidebar draws it.

const MAX_BYTES = 5 * 1024 * 1024;

export function BrandCard() {
  const t = useTranslations("system.brand");
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const brand = me?.brand;
  const titleId = useId();
  const fileId = useId();
  const [nameEn, setNameEn] = useState(brand?.name_en ?? "");
  const [nameAm, setNameAm] = useState(brand?.name_am ?? "");
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const save = useSaveBrandName();
  const uploadLogo = useUploadBrandLogo();
  const removeLogo = useRemoveBrandLogo();
  const nameKey = useIdempotencyKey();
  const logoKey = useIdempotencyKey();
  if (!can(me, "branding.manage")) return null;

  function onSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    // both names or neither (neither shows the app's own name)
    if (Boolean(nameEn.trim()) !== Boolean(nameAm.trim())) return setProblem(t("check_names"));
    setProblem(null);
    const body = { nameEn, nameAm };
    save.mutate(
      { ...body, idempotencyKey: nameKey.keyFor(body) },
      {
        onSuccess: (answer) => {
          nameKey.done();
          setSaved(answer.data.name_en ? t("saved_name") : t("saved_default"));
        },
      },
    );
  }

  function onFile(file: File | undefined) {
    setSaved(null);
    if (!file) return;
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) return setProblem(t("check_type"));
    if (file.size > MAX_BYTES) return setProblem(t("check_size"));
    setProblem(null);
    uploadLogo.mutate(
      { file, idempotencyKey: logoKey.keyFor({ name: file.name, size: file.size, modified: file.lastModified }) },
      {
        onSuccess: () => {
          logoKey.done();
          setSaved(t("saved_logo"));
        },
      },
    );
  }

  const busy = save.isPending || uploadLogo.isPending || removeLogo.isPending;
  const failure = save.error ?? uploadLogo.error ?? removeLogo.error;

  return (
    <section data-testid="system-brand" aria-labelledby={titleId} className={`${CARD} flex flex-col gap-5 p-5`}>
      <div className="space-y-1">
        <h2 id={titleId} className="text-[17px] font-semibold text-neutral-900">
          {t("title")}
        </h2>
        <p className="text-[15px] text-neutral-600">{t("intro")}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <form onSubmit={onSave} className="flex flex-col gap-4" noValidate>
          <Field label={t("name_en")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} lang="en" maxLength={120} autoComplete="organization" value={nameEn} onChange={(e) => setNameEn(e.target.value)} />}
          </Field>
          <Field label={t("name_am")} optionalLabel={t("optional")} hint={t("name_hint")}>
            {(props) => <TextInput {...props} lang="am" maxLength={120} autoComplete="off" value={nameAm} onChange={(e) => setNameAm(e.target.value)} />}
          </Field>
          <div>
            <Button type="submit" size="md" loading={save.isPending} disabled={busy}>
              {t("save_name")}
            </Button>
          </div>
        </form>

        <div className="flex flex-col gap-4">
          <p className="text-[15px] font-semibold text-neutral-900">{t("logo")}</p>
          <div className="flex items-center gap-4 rounded-2xl border border-neutral-200 bg-ground p-4">
            <AppMark size="lg" />
            <p className="min-w-0 text-sm text-neutral-700">{brand?.logo ? t("logo_set") : t("logo_default")}</p>
          </div>
          <p id={`${fileId}-hint`} className="text-[13px] text-neutral-600">
            {t("logo_hint")}
          </p>
          <div className="flex flex-wrap gap-3">
            {/* the file input inside its label: the label is the button people see, and its ring follows the input's keyboard focus */}
            <label className="relative inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-neutral-300 bg-white px-4 text-[15px] font-semibold text-neutral-900 hover:bg-neutral-100 has-[:disabled]:cursor-not-allowed has-[:disabled]:text-neutral-400 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-600/35 has-[:focus-visible]:ring-offset-1">
              <input
                id={fileId}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="sr-only"
                aria-describedby={`${fileId}-hint`}
                disabled={busy}
                onChange={(e) => {
                  onFile(e.target.files?.[0]);
                  e.target.value = "";
                }}
              />
              <ImageUp className="size-[18px]" strokeWidth={1.75} aria-hidden="true" />
              {uploadLogo.isPending ? t("uploading") : brand?.logo ? t("replace_logo") : t("upload_logo")}
            </label>
            {brand?.logo ? (
              <Button
                type="button"
                variant="ghost"
                size="md"
                disabled={busy}
                onClick={() => {
                  setSaved(null);
                  removeLogo.mutate(
                    { idempotencyKey: logoKey.keyFor({ remove: true }) },
                    {
                      onSuccess: () => {
                        logoKey.done();
                        setSaved(t("saved_no_logo"));
                      },
                    },
                  );
                }}
              >
                <Trash2 className="size-[18px]" strokeWidth={1.75} aria-hidden="true" />
                {t("remove_logo")}
              </Button>
            ) : null}
          </div>
        </div>
      </div>

      {problem ? <Alert>{problem}</Alert> : null}
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <Status>{saved}</Status>
    </section>
  );
}
