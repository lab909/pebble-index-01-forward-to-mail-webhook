"""Local Todoist API v1 stub; no real accounts or tokens are used."""
import json
from email import policy
from email.parser import BytesParser
import pathlib
import sys

# The existing tests/http.py must not shadow Python's standard-library http package.
original_path = sys.path[:]
sys.path = [path for path in sys.path if pathlib.Path(path).resolve() != pathlib.Path(__file__).parent]
try:
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
finally:
    sys.path = original_path

TOKEN = 'todoist-test-secret'


class TodoistStub:
    def __init__(self):
        self.requests = []
        self.projects = []
        self.tasks = []
        self.uploads = []
        self.comments = []
        self.fail = None
        stub = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def reply(self, status, data):
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps(data).encode())

            def do_GET(self):
                from urllib.parse import urlparse, parse_qs
                parsed = urlparse(self.path)
                stub.requests.append(('GET', self.path))
                if self.headers.get('Authorization') != f'Bearer {TOKEN}':
                    return self.reply(401, {'error': 'Invalid API token'})
                if stub.fail == 'projects':
                    return self.reply(403, {'error': f'Forbidden for {TOKEN}'})
                assert parsed.path == '/api/v1/projects', self.path
                # Force pagination so a matching project can be on a later page.
                index = int(parse_qs(parsed.query).get('cursor', ['0'])[0])
                projects = stub.projects[index:index + 1]
                cursor = str(index + 1) if index + 1 < len(stub.projects) else None
                self.reply(200, {'results': projects, 'next_cursor': cursor})

            def do_POST(self):
                stub.requests.append(('POST', self.path))
                if self.headers.get('Authorization') != f'Bearer {TOKEN}':
                    return self.reply(401, {'error': 'Invalid API token'})
                body = self.rfile.read(int(self.headers['Content-Length']))
                if self.path == '/api/v1/uploads':
                    message = BytesParser(policy=policy.default).parsebytes(
                        ('Content-Type: ' + self.headers['Content-Type'] + '\r\n\r\n').encode() + body)
                    parts = {part.get_param('name', header='content-disposition'): part
                             for part in message.iter_parts()}
                    file = parts['file']
                    if stub.fail == file.get_filename():
                        return self.reply(403, {'error': f'Upload denied for {TOKEN}'})
                    upload = {'file_name': file.get_filename(), 'file_type': file.get_content_type(),
                              'data': file.get_payload(decode=True),
                              'project_id': parts['project_id'].get_payload(decode=True).decode()}
                    stub.uploads.append(upload)
                    return self.reply(200, {'file_name': upload['file_name'], 'file_type': upload['file_type'],
                                           'file_url': f'https://files.todoist.com/test/{len(stub.uploads)}',
                                           'file_size': len(upload['data']), 'resource_type': 'file',
                                           'upload_state': 'completed'})
                data = json.loads(body)
                if self.path == '/api/v1/projects':
                    project = dict(data, id=f'project-{len(stub.projects)}')
                    stub.projects.append(project)
                    return self.reply(200, project)
                if self.path == '/api/v1/tasks':
                    if stub.fail == 'tasks':
                        return self.reply(429, {'error': f'Rate limited for {TOKEN}'})
                    task = dict(data, id=f'task-{len(stub.tasks)}')
                    stub.tasks.append(task)
                    return self.reply(200, task)
                if self.path == '/api/v1/comments':
                    if stub.fail == 'comments':
                        return self.reply(403, {'error': f'Comment denied for {TOKEN}'})
                    assert 'attachment' in data and 'file_attachment' not in data, data
                    comment = dict(data, id=f'comment-{len(stub.comments)}')
                    stub.comments.append(comment)
                    return self.reply(200, comment)
                self.reply(404, {'error': 'Unexpected endpoint'})

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.url = f'http://127.0.0.1:{self.server.server_port}/api/v1'
