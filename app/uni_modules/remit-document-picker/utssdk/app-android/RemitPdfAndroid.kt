package uts.sdk.modules.remitDocumentPicker

import android.app.Activity
import android.content.ContentResolver
import android.content.Intent
import android.net.Uri
import android.provider.OpenableColumns
import io.dcloud.uts.UTSAndroid
import java.io.File
import java.io.IOException
import java.util.UUID
import java.util.concurrent.Executors

object RemitPdfAndroid {
    private const val REQUEST_CODE = 38124
    private const val CACHE_DIRECTORY = "remit-pdf"
    private const val CACHE_LIFETIME_MS = 24 * 60 * 60 * 1000L
    private val cacheName = Regex("[0-9a-f-]{36}\\.pdf")
    private val io = Executors.newSingleThreadExecutor()
    private var picking = false

    fun pick(maxBytes: Number, callback: (String, String, String, Number) -> Unit) {
        val activity = UTSAndroid.getUniActivity()
        if (activity == null || maxBytes.toInt() !in 1..75_000) {
            callback("failed", "", "", 0)
            return
        }

        activity.runOnUiThread {
            if (picking || activity.isFinishing || activity.isDestroyed) {
                callback("failed", "", "", 0)
                return@runOnUiThread
            }
            picking = true
            val listener = object : (Int, Int, Intent?) -> Unit {
                override fun invoke(request: Int, result: Int, data: Intent?) {
                    if (request != REQUEST_CODE) return
                    UTSAndroid.offAppActivityResult(this)
                    val uri = data?.data
                    if (result == Activity.RESULT_CANCELED) {
                        picking = false
                        callback("cancelled", "", "", 0)
                    } else if (result != Activity.RESULT_OK || uri == null || uri.scheme != ContentResolver.SCHEME_CONTENT) {
                        picking = false
                        callback("failed", "", "", 0)
                    } else {
                        val app = activity.applicationContext
                        io.execute {
                            val file = readPdf(app.contentResolver, File(app.cacheDir, CACHE_DIRECTORY), uri, maxBytes.toInt())
                            activity.runOnUiThread {
                                picking = false
                                callback(file.status, file.path, file.name, file.size)
                            }
                        }
                    }
                }
            }

            UTSAndroid.onAppActivityResult(listener)
            try {
                val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = "application/pdf"
                }
                activity.startActivityForResult(intent, REQUEST_CODE)
            } catch (_: RuntimeException) {
                UTSAndroid.offAppActivityResult(listener)
                picking = false
                callback("failed", "", "", 0)
            }
        }
    }

    fun release(path: String) {
        val app = UTSAndroid.getAppContext() ?: return
        val directory = File(app.cacheDir, CACHE_DIRECTORY)
        val file = File(path)
        if (file.parentFile == directory && cacheName.matches(file.name)) {
            io.execute { file.delete() }
        }
    }

    private fun readPdf(resolver: ContentResolver, directory: File, uri: Uri, limit: Int): PickedFile {
        try {
            val data = resolver.openInputStream(uri)?.use { stream ->
                val bytes = ByteArray(limit + 1)
                var size = 0
                while (size < bytes.size) {
                    val read = stream.read(bytes, size, bytes.size - size)
                    if (read <= 0) break
                    size += read
                }
                bytes.copyOf(size)
            } ?: return PickedFile("failed")
            if (data.size > limit) return PickedFile("too_large")
            if (data.size < 5 || String(data, 0, 5, Charsets.US_ASCII) != "%PDF-") {
                return PickedFile("invalid")
            }

            val name = displayName(resolver, uri)
            if (!directory.isDirectory && !directory.mkdirs()) throw IOException()
            val cutoff = System.currentTimeMillis() - CACHE_LIFETIME_MS
            directory.listFiles()?.forEach { file ->
                if (cacheName.matches(file.name) && file.lastModified() < cutoff) file.delete()
            }

            val file = File(directory, UUID.randomUUID().toString() + ".pdf")
            try {
                file.writeBytes(data)
            } catch (error: IOException) {
                file.delete()
                throw error
            }
            return PickedFile("ok", file.absolutePath, name, data.size)
        } catch (_: IOException) {
            return PickedFile("failed")
        } catch (_: RuntimeException) {
            return PickedFile("failed")
        }
    }

    private fun displayName(resolver: ContentResolver, uri: Uri): String {
        val name = try {
            resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { cursor ->
                if (cursor.moveToFirst()) cursor.getString(0) else null
            }
        } catch (_: RuntimeException) {
            null
        }
        return name?.takeIf { it.isNotBlank() } ?: "补件.pdf"
    }

    private data class PickedFile(
        val status: String,
        val path: String = "",
        val name: String = "",
        val size: Int = 0,
    )
}
