import urllib.request

conn = urllib.request.Request("http://api.tvmaze.com/shows", headers={'User-Agent': 'Mozilla/5.0'})

r = urllib.request.urlopen(conn)
headers = r.getheaders()
for i in headers:
	print(i)
print()

