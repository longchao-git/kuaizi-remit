type AttachmentKind = "PDF" | "PNG" | "JPG";

export interface PreviewAttachment {
  name: string;
  kind: AttachmentKind;
  size: string;
}

const maxBytes = 10 * 1024 * 1024;
const extensions = new Map<string, AttachmentKind>([
  ["pdf", "PDF"],
  ["png", "PNG"],
  ["jpg", "JPG"],
  ["jpeg", "JPG"],
]);

let attachmentFormats = "JPG、PNG";
// #ifdef H5
attachmentFormats = "PDF、JPG、PNG";
// #endif
export { attachmentFormats };

function isSupportedSize(size: number) {
  return Number.isFinite(size) && size > 0 && size <= maxBytes;
}

function toPreview(name: string, size: number, kind: AttachmentKind): PreviewAttachment {
  return { name, kind, size: Math.max(1, Math.round(size / 1024)) + " KB" };
}

// #ifdef H5
const fileSignatures: Record<AttachmentKind, number[]> = {
  PDF: [0x25, 0x50, 0x44, 0x46, 0x2d],
  PNG: [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a],
  JPG: [0xff, 0xd8, 0xff],
};

async function matchesFileHeader(file: File, kind: AttachmentKind) {
  const signature = fileSignatures[kind];
  const bytes = new Uint8Array(await file.slice(0, signature.length).arrayBuffer());
  return signature.every((value, index) => bytes[index] === value);
}
// #endif

export function pickAttachment(
  select: (file: PreviewAttachment) => void,
  isCurrent: () => boolean,
  showError: (message: string) => void,
) {
  const reportInvalid = (message = "请选择支持格式的资料，大小须在 10 MB 以内") => showError(message);
  const fail = (error: { errMsg?: string }) => {
    if (!isCurrent() || error.errMsg?.toLowerCase().includes("cancel")) return;
    reportInvalid("打开资料选择失败，请重试");
  };

  // #ifdef H5
  uni.chooseFile({
    count: 1,
    type: "all",
    extension: [".pdf", ".jpg", ".jpeg", ".png"],
    async success(result) {
      if (!isCurrent()) return;
      const file = Array.isArray(result.tempFiles) ? result.tempFiles[0] : result.tempFiles;
      if (!file || !("name" in file)) return;
      const kind = extensions.get(file.name.split(".").pop()?.toLowerCase() ?? "");
      if (!kind || !isSupportedSize(file.size)) {
        reportInvalid();
        return;
      }

      try {
        const matches = await matchesFileHeader(file, kind);
        if (!isCurrent()) return;
        if (!matches) {
          reportInvalid("文件内容与格式不符，请重新选择");
          return;
        }
        select(toPreview(file.name, file.size, kind));
      } catch {
        if (isCurrent()) reportInvalid("无法读取所选资料，请重新选择");
      }
    },
    fail,
  });
  // #endif
  // #ifndef H5
  uni.chooseImage({
    count: 1,
    sizeType: ["original"],
    sourceType: ["album"],
    success(result) {
      if (!isCurrent()) return;
      const file = Array.isArray(result.tempFiles) ? result.tempFiles[0] : result.tempFiles;
      if (!file) return;
      const name = ("name" in file ? file.name : file.path.split("/").pop()) || "";
      const kind = extensions.get(name.split(".").pop()?.toLowerCase() ?? "");
      if (!kind || kind === "PDF" || !isSupportedSize(file.size)) {
        reportInvalid();
        return;
      }
      select(toPreview(name, file.size, kind));
    },
    fail,
  });
  // #endif
}
