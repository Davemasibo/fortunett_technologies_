"""Loopback-only RADIUS protocol double. Uses invented credentials."""
import hashlib
import hmac
import socket
import struct

secret = b"TEST-ONLY-SECRET"
with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as server:
    server.bind(("127.0.0.1", 0))
    server.settimeout(10)
    print(server.getsockname()[1], flush=True)
    for _ in range(3):
        packet, peer = server.recvfrom(4096)
        code, identifier, length = struct.unpack("!BBH", packet[:4])
        assert code == 1 and length == len(packet)
        attributes = {}
        authenticator = packet[4:20]
        offset = 20
        unsigned = bytearray(packet)
        while offset < length:
            kind, size = packet[offset:offset + 2]
            attributes[kind] = packet[offset + 2:offset + size]
            if kind == 80:
                unsigned[offset + 2:offset + size] = bytes(16)
            offset += size
        assert hmac.compare_digest(attributes[80], hmac.new(secret, unsigned, hashlib.md5).digest())
        cipher = attributes[2]
        password = b""
        previous = authenticator
        for start in range(0, len(cipher), 16):
            block = cipher[start:start + 16]
            key = hashlib.md5(secret + previous).digest()
            password += bytes(a ^ b for a, b in zip(block, key))
            previous = block
        password = password.rstrip(b"\0")
        accept = attributes[1] in (b"test-user", b"tampered") and password == b"right-password"
        header = struct.pack("!BBH", 2 if accept else 3, identifier, 20)
        response_auth = hashlib.md5(header + authenticator + secret).digest()
        if attributes[1] == b"tampered":
            response_auth = bytes(16)
        server.sendto(header + response_auth, peer)
