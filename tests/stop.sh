for i in {9100..9104} ; do
    echo ""
    redis-cli -p $i shutdown
done