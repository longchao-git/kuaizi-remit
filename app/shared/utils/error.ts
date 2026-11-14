export function getErrorMessage(error: unknown, fallbackMessage = '操作失败，请稍后重试'): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }

  if (typeof error === 'object' && error !== null) {
    const message = (error as { msg?: string; message?: string }).message
      || (error as { msg?: string; message?: string }).msg;
    if (message) {
      return message;
    }
  }

  return fallbackMessage;
}
