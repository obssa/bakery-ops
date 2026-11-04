"use client";

import { Move } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useId, useRef, type KeyboardEvent, type PointerEvent } from "react";
import { imageStyle, MAX_ZOOM, MIN_ZOOM, panBy, zoomAt, type FrameView, type FramePoint, type Size } from "@/lib/images/frame";
import { focusRing } from "../styles";

// The framing step of the Photo card (step 6.4b; fresh-counter.md 4.3; the ProductPhoto board): the photo inside a fixed 4:3 frame on ink,
// with the rule-of-thirds lines and the "Drag to move" chip, and under it the zoom slider from 1x to 3x. The maths are lib/images/frame.ts;
// this file turns pointers, keys and the wheel into views.
//   - One pointer drags the photo (a mouse, a pen, a finger); two fingers pinch, zooming where they are and moving with their midpoint.
//   - The frame takes the focus: the arrow keys move the photo (Shift for bigger steps), plus and minus zoom.
//   - A trackpad's pinch (a wheel event with ctrlKey) zooms at the pointer; a plain wheel scrolls the page as ever.
// The view lives with the card, so the live preview (FramedImage) draws the same rectangle.

/** A photo the browser decoded: the file to send (lib/images/downscale.ts PRODUCT_PHOTO), its size and an object URL to draw it. */
export interface PreparedPhoto extends Size {
  blob: Blob;
  url: string;
}

/** The photo drawn as the frame shows it, filling a box of any size that is 4:3 (the frame, the pad card's photo, the 48 px thumb). */
export function FramedImage({ photo, view, alt = "", className = "" }: { photo: PreparedPhoto; view: FrameView; alt?: string; className?: string }) {
  const place = imageStyle(photo, view);
  return (
    // a blob: URL of the photo being framed; nothing to optimise
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={photo.url}
      alt={alt}
      draggable={false}
      width={photo.width}
      height={photo.height}
      style={{ left: `${place.left}%`, top: `${place.top}%`, width: `${place.width}%`, height: `${place.height}%` }}
      className={`pointer-events-none absolute block max-w-none select-none ${className}`}
    />
  );
}

const KEY_STEP = 12;
const KEY_STEP_LARGE = 60;
const ZOOM_STEP = 0.1;

export function PhotoFramer({
  photo,
  view,
  name,
  disabled = false,
  onView,
}: {
  photo: PreparedPhoto;
  view: FrameView;
  /** the product's name in the page's language (the photo's alt) */
  name: string;
  disabled?: boolean;
  onView: (update: (view: FrameView) => FrameView) => void;
}) {
  const t = useTranslations("photo");
  const helpId = useId();
  const zoomId = useId();
  const frame = useRef<HTMLDivElement>(null);
  const pointers = useRef(new Map<number, { x: number; y: number }>());
  const pinch = useRef<{ distance: number; zoom: number } | null>(null);

  const width = () => frame.current?.getBoundingClientRect().width ?? 0;
  /** A point of the window as a point of the frame (fractions of its width and height). */
  const inFrame = (x: number, y: number): FramePoint => {
    const box = frame.current?.getBoundingClientRect();
    if (!box || box.width === 0 || box.height === 0) return { x: 0.5, y: 0.5 };
    return { x: Math.min(1, Math.max(0, (x - box.left) / box.width)), y: Math.min(1, Math.max(0, (y - box.top) / box.height)) };
  };

  function twoFingers(): { mid: { x: number; y: number }; distance: number } | null {
    const [a, b] = Array.from(pointers.current.values());
    if (!a || !b) return null;
    return { mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, distance: Math.hypot(a.x - b.x, a.y - b.y) };
  }

  function onPointerDown(event: PointerEvent<HTMLDivElement>) {
    if (disabled || (event.pointerType === "mouse" && event.button !== 0)) return;
    event.currentTarget.setPointerCapture(event.pointerId);
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    const fingers = twoFingers();
    pinch.current = fingers && fingers.distance > 0 ? { distance: fingers.distance, zoom: view.zoom } : null;
  }

  function onPointerMove(event: PointerEvent<HTMLDivElement>) {
    const before = pointers.current.get(event.pointerId);
    if (!before) return;
    const fingersBefore = twoFingers();
    pointers.current.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pointers.current.size === 1) {
      const dx = event.clientX - before.x;
      const dy = event.clientY - before.y;
      onView((current) => panBy(photo, current, width(), dx, dy));
      return;
    }
    const fingers = twoFingers();
    if (!fingers || !fingersBefore || !pinch.current) return;
    const zoom = pinch.current.zoom * (fingers.distance / pinch.current.distance);
    const anchor = inFrame(fingers.mid.x, fingers.mid.y);
    const dx = fingers.mid.x - fingersBefore.mid.x;
    const dy = fingers.mid.y - fingersBefore.mid.y;
    onView((current) => panBy(photo, zoomAt(photo, current, zoom, anchor), width(), dx, dy));
  }

  function onPointerEnd(event: PointerEvent<HTMLDivElement>) {
    pointers.current.delete(event.pointerId);
    if (pointers.current.size < 2) pinch.current = null;
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (disabled) return;
    const step = event.shiftKey ? KEY_STEP_LARGE : KEY_STEP;
    // an arrow moves the photo that way, as a drag would
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    const move = moves[event.key];
    if (move) {
      event.preventDefault();
      onView((current) => panBy(photo, current, width(), move[0], move[1]));
      return;
    }
    if (event.key === "+" || event.key === "=") {
      event.preventDefault();
      onView((current) => zoomAt(photo, current, current.zoom + ZOOM_STEP));
    } else if (event.key === "-" || event.key === "_") {
      event.preventDefault();
      onView((current) => zoomAt(photo, current, current.zoom - ZOOM_STEP));
    }
  }

  // a trackpad's pinch arrives as a wheel with ctrlKey; React's wheel listener is passive, so the page would zoom too
  useEffect(() => {
    const element = frame.current;
    if (!element || disabled) return;
    const onWheel = (event: WheelEvent) => {
      if (!event.ctrlKey) return;
      event.preventDefault();
      const box = element.getBoundingClientRect();
      const anchor = { x: (event.clientX - box.left) / box.width, y: (event.clientY - box.top) / box.height };
      onView((current) => zoomAt(photo, current, current.zoom * Math.exp(-event.deltaY * 0.01), anchor));
    };
    element.addEventListener("wheel", onWheel, { passive: false });
    return () => element.removeEventListener("wheel", onWheel);
  }, [disabled, onView, photo]);

  const percent = Math.round(view.zoom * 100);
  const zoomText = t("zoom_value", { zoom: (Math.round(view.zoom * 10) / 10).toFixed(1) });

  return (
    <>
      <div
        ref={frame}
        role="group"
        aria-label={t("frame_label")}
        aria-describedby={helpId}
        tabIndex={disabled ? -1 : 0}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerEnd}
        onPointerCancel={onPointerEnd}
        onLostPointerCapture={onPointerEnd}
        onKeyDown={onKeyDown}
        className={`relative aspect-[4/3] touch-none select-none overflow-hidden rounded-[14px] bg-neutral-900 ${disabled ? "" : "cursor-grab active:cursor-grabbing"} ${focusRing}`}
      >
        <FramedImage photo={photo} view={view} alt={t("frame_alt", { name })} />
        {/* the rule of thirds */}
        <div aria-hidden="true" className="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3">
          {Array.from({ length: 9 }, (_, cell) => (
            <span key={cell} className={`${cell % 3 < 2 ? "border-r" : ""} ${cell < 6 ? "border-b" : ""} border-white/55`} />
          ))}
        </div>
        <span aria-hidden="true" className="pointer-events-none absolute bottom-3 left-3 inline-flex h-7.5 items-center gap-2 rounded-full bg-neutral-900/78 px-3 text-[13px] font-semibold text-white">
          <Move className="size-4" strokeWidth={2} aria-hidden="true" />
          {t("drag_chip")}
        </span>
        <span id={helpId} className="sr-only">
          {t("frame_help")}
        </span>
      </div>
      <div className="flex items-center gap-3">
        <label htmlFor={zoomId} className="w-11 shrink-0 text-sm font-semibold text-neutral-900">
          {t("zoom")}
        </label>
        <input
          id={zoomId}
          type="range"
          min={MIN_ZOOM * 100}
          max={MAX_ZOOM * 100}
          step={1}
          value={percent}
          disabled={disabled}
          aria-valuetext={zoomText}
          onChange={(event) => {
            const zoom = Number(event.target.value) / 100;
            onView((current) => zoomAt(photo, current, zoom));
          }}
          className={`h-11 min-w-0 grow cursor-pointer accent-brand-600 disabled:cursor-not-allowed ${focusRing}`}
        />
        <span aria-hidden="true" className="w-12 shrink-0 text-right text-sm font-semibold tabular-nums text-neutral-900">
          {zoomText}
        </span>
      </div>
    </>
  );
}
