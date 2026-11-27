import CryptoKit
import Foundation
import Security

final class RemitSignerIOS {
    private static let tag = Data("com.kuaizi.remit.request-signing.v1".utf8)
    private static let lock = NSLock()

    @available(iOS 13.0, *)
    private static func privateKey() -> SecKey? {
        lock.lock()
        defer { lock.unlock() }

        let query: [String: Any] = [
            kSecClass as String: kSecClassKey,
            kSecAttrApplicationTag as String: tag,
            kSecAttrKeyType as String: kSecAttrKeyTypeECSECPrimeRandom,
            kSecAttrKeyClass as String: kSecAttrKeyClassPrivate,
            kSecReturnRef as String: true
        ]
        var stored: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &stored)
        if status == errSecSuccess, let item = stored, CFGetTypeID(item) == SecKeyGetTypeID() {
            return (item as! SecKey)
        }
        guard status == errSecItemNotFound,
              let access = SecAccessControlCreateWithFlags(nil, kSecAttrAccessibleWhenUnlockedThisDeviceOnly, .privateKeyUsage, nil) else { return nil }

        let attributes: [String: Any] = [
            kSecAttrKeyType as String: kSecAttrKeyTypeECSECPrimeRandom,
            kSecAttrKeySizeInBits as String: 256,
            kSecAttrTokenID as String: kSecAttrTokenIDSecureEnclave,
            kSecPrivateKeyAttrs as String: [
                kSecAttrIsPermanent as String: true,
                kSecAttrApplicationTag as String: tag,
                kSecAttrAccessControl as String: access
            ]
        ]
        return SecKeyCreateRandomKey(attributes as CFDictionary, nil)
    }

    static func publicKey() -> String {
        guard #available(iOS 13.0, *), let key = privateKey(), let onDevicePublicKey = SecKeyCopyPublicKey(key),
              let point = SecKeyCopyExternalRepresentation(onDevicePublicKey, nil) else { return "" }
        return (point as Data).base64EncodedString()
    }

    static func sign(_ method: String, _ path: String, _ body: String, _ bearer: String) -> String {
        guard #available(iOS 13.0, *), let key = privateKey() else { return "" }
        let time = String(Int(Date().timeIntervalSince1970))
        let nonce = UUID().uuidString.lowercased().replacingOccurrences(of: "-", with: "")
        let message = ["remit-v1", method, path, digest(body), digest(bearer), time, nonce].joined(separator: "\n")
        guard let result = SecKeyCreateSignature(key, .ecdsaSignatureMessageX962SHA256, Data(message.utf8) as CFData, nil) else { return "" }
        return "\(time)|\(nonce)|\((result as Data).base64EncodedString())"
    }

    @available(iOS 13.0, *)
    private static func digest(_ value: String) -> String {
        SHA256.hash(data: Data(value.utf8)).map { String(format: "%02x", Int($0)) }.joined()
    }
}
