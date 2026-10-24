"""Local-only origin/proxy protocol fixture; never opens an outbound socket."""
import json
import socket
import ssl
import sys
import threading

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
context.set_servername_callback(lambda sock, name, ctx: record({"sni": name}))
events = []
lock = threading.Lock()


def record(event):
    with lock:
        events.append(event)


def headers(conn):
    data = b""
    while not data.endswith(b"\r\n\r\n") and len(data) < 65536:
        part = conn.recv(1)
        if not part:
            break
        data += part
    return data.decode("latin1")


def serve(conn, mode):
    conn.settimeout(3)
    try:
        if mode == "proxy":
            request = headers(conn)
            record({"proxy": request})
            if "Proxy-Authorization: Basic c3ludGhldGljOnN5bnRoZXRpYw==" not in request:
                conn.sendall(b'HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm="fixture"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
                return
            if not request.startswith("CONNECT "):
                conn.sendall(b"HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n")
                return
            if ":8080 " in request.splitlines()[0]:
                conn.sendall(b"HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n")
                return
            conn.sendall(b"HTTP/1.1 200 Connection established\r\n\r\n")
            if ":443 " in request.splitlines()[0]:
                conn = context.wrap_socket(conn, server_side=True)
        elif mode == "tls":
            conn = context.wrap_socket(conn, server_side=True)
        request = headers(conn)
        if not request:
            return
        record({"origin": request})
        first = request.splitlines()[0]
        path = first.split(" ")[1]
        if path == "/redirect":
            conn.sendall(b"HTTP/1.1 302 Found\r\nLocation: http://169.254.169.254/private\r\nContent-Length: 0\r\n\r\n")
        elif path == "/redirect-ok":
            conn.sendall(b"HTTP/1.1 302 Found\r\nLocation: /ok\r\nContent-Length: 0\r\n\r\n")
        elif path == "/oversized":
            conn.sendall(b"HTTP/1.1 200 OK\r\nX-Large: " + b"x" * 70000 + b"\r\n\r\n")
        elif path == "/head405" and first.startswith("HEAD "):
            conn.sendall(b"HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n")
        else:
            conn.sendall(b"HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\n")
            if not first.startswith("HEAD "):
                conn.sendall(b"body")
    except (OSError, ssl.SSLError):
        pass
    finally:
        conn.close()


def accept(listener, mode):
    while True:
        try:
            conn, _ = listener.accept()
        except OSError:
            return
        threading.Thread(target=serve, args=(conn, mode), daemon=True).start()


ports = {}
for mode in ("plain", "tls", "proxy"):
    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.bind(("127.0.0.1", 0))
    listener.listen(32)
    ports[mode] = listener.getsockname()[1]
    threading.Thread(target=accept, args=(listener, mode), daemon=True).start()
print(json.dumps(ports), flush=True)
for line in sys.stdin:
    if line.strip() == "events":
        with lock:
            print(json.dumps(events), flush=True)
    elif line.strip() == "stop":
        break
