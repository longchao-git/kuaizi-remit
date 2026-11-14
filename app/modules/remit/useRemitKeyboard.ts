import { nextTick, onMounted, onUnmounted, watch } from "vue";

type Sheet = "country" | "aio" | undefined;

export function useRemitKeyboard(getSheet: () => Sheet, closeSheet: () => void) {
  // #ifdef H5
  let root: HTMLElement | null = null;
  const focusable = '[tabindex="0"]:not([disabled])';

  function onKeyDown(event: KeyboardEvent) {
    const dialog = root?.querySelector<HTMLElement>(".sheet[aria-modal=true]");
    if (dialog && event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      closeSheet();
      return;
    }
    if (dialog && event.key === "Tab") {
      const controls = dialog.querySelectorAll<HTMLElement>(focusable);
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (
        first &&
        last &&
        (!dialog.contains(document.activeElement) || document.activeElement === (event.shiftKey ? first : last))
      ) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
      return;
    }
    if (event.key !== "Enter" && event.key !== " ") return;
    if (!(event.target instanceof HTMLElement)) return;
    const control = event.target.closest<HTMLElement>(
      'uni-button[tabindex="0"], uni-text[role="link"][tabindex="0"]',
    );
    if (
      !control ||
      control.hasAttribute("disabled") ||
      (event.key === " " && control.getAttribute("role") === "link")
    )
      return;
    event.preventDefault();
    if (!event.repeat) control.click();
  }

  watch(getSheet, async (sheet, previous) => {
    await nextTick();
    if (!root || sheet !== getSheet()) return;
    if (sheet) {
      root.querySelector<HTMLElement>(".sheet " + focusable)?.focus();
    } else if (previous) {
      root
        .querySelector<HTMLElement>(previous === "country" ? ".country-trigger" : ".aio-trigger")
        ?.focus({ preventScroll: true });
    }
  });

  onMounted(() => {
    root = document.querySelector<HTMLElement>(".remit");
    root?.addEventListener("keydown", onKeyDown);
  });
  onUnmounted(() => root?.removeEventListener("keydown", onKeyDown));
  // #endif
}
