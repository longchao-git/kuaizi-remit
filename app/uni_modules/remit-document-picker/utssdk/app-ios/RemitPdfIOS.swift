import Foundation
import UIKit
import UniformTypeIdentifiers

final class RemitPdfIOS: NSObject, UIDocumentPickerDelegate {
    typealias Completion = (String, String, String, NSNumber) -> Void

    private static var active: RemitPdfIOS?
    private static let io = DispatchQueue(label: "com.kuaizi.remit.pdf-picker")

    private let limit: Int
    private let completion: Completion

    private init(limit: Int, completion: @escaping Completion) {
        self.limit = limit
        self.completion = completion
    }

    static func pick(_ presenter: UIViewController?, _ maxBytes: NSNumber, _ completion: @escaping Completion) {
        let limit = maxBytes.intValue
        guard active == nil, let presenter, presenter.viewIfLoaded?.window != nil,
              presenter.presentedViewController == nil, (1...75_000).contains(limit) else {
            completion("failed", "", "", 0)
            return
        }

        let delegate = RemitPdfIOS(limit: limit, completion: completion)
        let picker: UIDocumentPickerViewController
        if #available(iOS 14.0, *) {
            picker = UIDocumentPickerViewController(forOpeningContentTypes: [.pdf], asCopy: false)
        } else {
            picker = UIDocumentPickerViewController(documentTypes: ["com.adobe.pdf"], in: .open)
        }
        picker.delegate = delegate
        picker.allowsMultipleSelection = false
        if #available(iOS 13.0, *) {
            picker.isModalInPresentation = true
        }
        active = delegate
        presenter.present(picker, animated: true)
    }

    func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
        finish("cancelled")
    }

    func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
        guard let url = urls.first else {
            finish("failed")
            return
        }

        Self.io.async {
            let access = url.startAccessingSecurityScopedResource()
            defer {
                if access { url.stopAccessingSecurityScopedResource() }
            }

            var coordinationError: NSError?
            var result = ("failed", "", "", 0)
            NSFileCoordinator().coordinate(readingItemAt: url, options: [], error: &coordinationError) { readable in
                result = self.readPdf(readable, name: url.lastPathComponent)
            }
            let (status, path, name, size) = result
            DispatchQueue.main.async {
                self.finish(status, path: path, name: name, size: size)
            }
        }
    }

    private func readPdf(_ url: URL, name: String) -> (String, String, String, Int) {
        do {
            let handle = try FileHandle(forReadingFrom: url)
            defer { handle.closeFile() }
            var data = Data()
            while data.count <= limit {
                let chunk = handle.readData(ofLength: min(16_384, limit + 1 - data.count))
                if chunk.isEmpty { break }
                data.append(chunk)
            }
            if data.count > limit { return ("too_large", "", "", 0) }
            guard data.prefix(5).elementsEqual("%PDF-".utf8) else { return ("invalid", "", "", 0) }

            let directory = Self.directory
            let manager = FileManager.default
            try manager.createDirectory(at: directory, withIntermediateDirectories: true)
            let cutoff = Date().addingTimeInterval(-86_400)
            for item in (try? manager.contentsOfDirectory(at: directory, includingPropertiesForKeys: [.contentModificationDateKey])) ?? [] {
                guard Self.isCacheFile(item),
                      let modified = try? item.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate,
                      modified < cutoff else { continue }
                try? manager.removeItem(at: item)
            }

            let file = directory.appendingPathComponent(UUID().uuidString).appendingPathExtension("pdf")
            try data.write(to: file, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            return ("ok", file.path, name.isEmpty ? "补件.pdf" : name, data.count)
        } catch {
            return ("failed", "", "", 0)
        }
    }

    static func release(_ path: String) {
        let file = URL(fileURLWithPath: path, isDirectory: false)
        guard isCacheFile(file) else { return }
        io.async { try? FileManager.default.removeItem(at: file) }
    }

    private static var directory: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("remit-pdf", isDirectory: true)
    }

    private static func isCacheFile(_ file: URL) -> Bool {
        file.deletingLastPathComponent().path == directory.path &&
            file.pathExtension == "pdf" && UUID(uuidString: file.deletingPathExtension().lastPathComponent) != nil
    }

    private func finish(_ status: String, path: String = "", name: String = "", size: Int = 0) {
        guard Self.active === self else {
            if !path.isEmpty { Self.release(path) }
            return
        }
        Self.active = nil
        completion(status, path, name, NSNumber(value: size))
    }
}
