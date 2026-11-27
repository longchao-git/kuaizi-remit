package uts.sdk.modules.remitDeviceSigning

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.math.BigInteger
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.MessageDigest
import java.security.Signature
import java.security.interfaces.ECPublicKey
import java.security.spec.ECGenParameterSpec
import java.util.UUID

object RemitSignerAndroid {
    private const val ALIAS = "kuaizi-remit-request-signing-v1"
    private const val KEYSTORE = "AndroidKeyStore"

    @Synchronized
    private fun entry(): KeyStore.PrivateKeyEntry {
        require(Build.VERSION.SDK_INT >= Build.VERSION_CODES.M)
        val store = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        if (!store.containsAlias(ALIAS)) {
            val generator = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, KEYSTORE)
            generator.initialize(
                KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_SIGN)
                    .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                    .setDigests(KeyProperties.DIGEST_SHA256)
                    .build()
            )
            generator.generateKeyPair()
        }
        return store.getEntry(ALIAS, null) as KeyStore.PrivateKeyEntry
    }

    private fun coordinate(value: BigInteger): ByteArray {
        val bytes = value.toByteArray().let { if (it.size == 33 && it[0] == 0.toByte()) it.copyOfRange(1, it.size) else it }
        require(bytes.size <= 32 && value.signum() >= 0)
        return ByteArray(32 - bytes.size) + bytes
    }

    fun publicKey(): String = try {
        val point = (entry().certificate.publicKey as ECPublicKey).w
        Base64.encodeToString(byteArrayOf(4) + coordinate(point.affineX) + coordinate(point.affineY), Base64.NO_WRAP)
    } catch (_: Exception) {
        ""
    }

    fun sign(method: String, path: String, body: String, bearer: String): String = try {
        val time = (System.currentTimeMillis() / 1000).toString()
        val nonce = UUID.randomUUID().toString().replace("-", "")
        val message = listOf("remit-v1", method, path, digest(body), digest(bearer), time, nonce).joinToString("\n")
        val signature = Signature.getInstance("SHA256withECDSA").apply {
            initSign(entry().privateKey)
            update(message.toByteArray(Charsets.UTF_8))
        }.sign()
        "$time|$nonce|${Base64.encodeToString(signature, Base64.NO_WRAP)}"
    } catch (_: Exception) {
        ""
    }

    private fun digest(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8)).joinToString("") { "%02x".format(it.toInt() and 0xff) }
}
