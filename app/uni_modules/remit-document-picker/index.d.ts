export function pickPdf(
  maxBytes: number,
  callback: (status: string, path: string, name: string, size: number) => void,
): void;
export function releasePdf(path: string): void;
